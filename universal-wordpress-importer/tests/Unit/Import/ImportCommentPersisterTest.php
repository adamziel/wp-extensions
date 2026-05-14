<?php
/**
 * Tests for staged REST comment persistence.
 *
 * @package UniversalImporter\Tests\Unit\Import
 */

namespace UniversalImporter\Tests\Unit\Import;

use PHPUnit\Framework\TestCase;
use UniversalImporter\Import\ImportCommentPersister;
use UniversalImporter\Import\ImportIdempotencyRecord;
use UniversalImporter\Import\ImportPreparedDocument;
use UniversalImporter\Import\ImportRunnerControls;
use UniversalImporter\Import\ImportSession;
use UniversalImporter\Import\ImportSimulatedCrashException;
use UniversalImporter\Import\WordPressImportSessionStore;

/**
 * Covers idempotent staged REST comment persistence without WordPress loaded.
 */
final class ImportCommentPersisterTest extends TestCase {
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
	 * Fake post gateway.
	 *
	 * @var FakePostGateway
	 */
	private $posts;

	/**
	 * Fake comment gateway.
	 *
	 * @var FakeCommentGateway
	 */
	private $comments;

	/**
	 * Sets up test dependencies.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->wpdb     = new FakeWpdb();
		$this->store    = new WordPressImportSessionStore( $this->wpdb );
		$this->posts    = new FakePostGateway();
		$this->comments = new FakeCommentGateway();
	}

	/**
	 * Staged comments are imported under the local post with remote parents mapped to local ids.
	 *
	 * @return void
	 */
	public function test_persister_creates_comments_with_parent_mapping() {
		$session  = ImportSession::start_for_source( 'https://source.example.test/' );
		$document = $this->document_with_comments( $session );

		$this->store->save( $session );
		$this->store->save_prepared_document( $document );
		$post_id = $this->posts->insert_or_update( $document );
		$this->store->remember_idempotency_record(
			$session->get_id(),
			new ImportIdempotencyRecord( 'post:remote:post:31', 'post', (string) $post_id, $document->get_content_hash() )
		);

		$summary  = ( new ImportCommentPersister( $this->store, $this->posts, $this->comments ) )->advance( $session );
		$parent   = $this->comments->get_comment( 1000 );
		$child    = $this->comments->get_comment( 1001 );
		$restored = $this->store->find_prepared_document( $session->get_id(), 'remote:post:31' );
		$events   = $this->event_types( $session );

		$this->assertSame( 2, $summary['created'] );
		$this->assertSame( 0, $summary['failed'] );
		$this->assertSame( 1, $parent['comment_post_ID'] );
		$this->assertSame( 0, $parent['comment_parent'] );
		$this->assertSame( 1000, $child['comment_parent'] );
		$this->assertSame( 2, $this->comments->count_comments() );
		$this->assertSame( 1001, $restored->get_metadata()['local_comments']['102']['local_comment_id'] );
		$this->assertSame( 1000, $restored->get_metadata()['local_comments']['102']['local_parent_id'] );
		$this->assertSame( 2, $restored->get_metadata()['remote_comments_imported'] );
		$this->assertNotNull( $this->store->find_idempotency_record( $session->get_id(), 'comment:remote:post:31:101' ) );
		$this->assertContains( 'comment.created', $events );
	}

	/**
	 * Re-running the persister does not create duplicate comments for unchanged payloads.
	 *
	 * @return void
	 */
	public function test_persister_skips_unchanged_comments_with_matching_idempotency_records() {
		$session  = ImportSession::start_for_source( 'https://source.example.test/' );
		$document = $this->document_with_comments( $session );

		$this->store->save( $session );
		$this->store->save_prepared_document( $document );
		$post_id = $this->posts->insert_or_update( $document );
		$this->store->remember_idempotency_record(
			$session->get_id(),
			new ImportIdempotencyRecord( 'post:remote:post:31', 'post', (string) $post_id, $document->get_content_hash() )
		);

		$persister = new ImportCommentPersister( $this->store, $this->posts, $this->comments );
		$persister->advance( $session );
		$summary = $persister->advance( $session );

		$this->assertSame( 2, $this->comments->count_comments() );
		$this->assertSame( 2, $summary['skipped'] );
		$this->assertSame( 0, $summary['created'] );
		$this->assertSame( 0, $summary['updated'] );
	}

	/**
	 * Comment persistence scans beyond the first prepared-document page.
	 *
	 * @return void
	 */
	public function test_persister_finds_staged_comments_after_first_document_page() {
		$session = ImportSession::start_for_source( 'https://source.example.test/' );

		$this->store->save( $session );

		for ( $index = 1; $index <= 30; ++$index ) {
			$source_item_key = 'remote:post:' . str_pad( (string) $index, 3, '0', STR_PAD_LEFT );
			$document        = $this->document_with_remote_comment_key( $session, $source_item_key, 100 + $index );

			if ( 30 !== $index ) {
				$document = $document->with_metadata( array( 'relative_path' => 'post-' . $index ) );
			}

			$this->store->save_prepared_document( $document );
		}

		$late_document = $this->store->find_prepared_document( $session->get_id(), 'remote:post:030' );
		$post_id       = $this->posts->insert_or_update( $late_document );
		$this->store->remember_idempotency_record(
			$session->get_id(),
			new ImportIdempotencyRecord( 'post:remote:post:030', 'post', (string) $post_id, $late_document->get_content_hash() )
		);

		$summary = ( new ImportCommentPersister( $this->store, $this->posts, $this->comments ) )->advance( $session, 25 );

		$this->assertSame( 1, $summary['created'] );
		$this->assertSame( 1, $this->comments->count_comments() );
		$this->assertNotNull( $this->store->find_idempotency_record( $session->get_id(), 'comment:remote:post:030:130' ) );
	}

	/**
	 * A crash after the WordPress comment write but before idempotency is recovered by metadata lookup.
	 *
	 * @return void
	 */
	public function test_persister_recovers_after_comment_write_idempotency_gap() {
		$session  = ImportSession::start_for_source( 'https://source.example.test/' );
		$document = $this->document_with_comments( $session );

		$this->store->save( $session );
		$this->store->save_prepared_document( $document );
		$post_id = $this->posts->insert_or_update( $document );
		$this->store->remember_idempotency_record(
			$session->get_id(),
			new ImportIdempotencyRecord( 'post:remote:post:31', 'post', (string) $post_id, $document->get_content_hash() )
		);

		try {
			( new ImportCommentPersister( $this->store, $this->posts, $this->comments, new ImportRunnerControls( false, false, 0, null, false, false, true ) ) )->advance( $session );
			$this->fail( 'Expected a controlled crash after the comment write.' );
		} catch ( ImportSimulatedCrashException $exception ) {
			$this->assertSame( 'Simulated importer crash after comment write and before idempotency record.', $exception->getMessage() );
		}

		$this->assertSame( 1, $this->comments->count_comments() );
		$this->assertNull( $this->store->find_idempotency_record( $session->get_id(), 'comment:remote:post:31:101' ) );
		$this->assertSame( 'comment.simulated_crash_after_write', $this->store->list_events( $session->get_id(), 1 )[0]->get_type() );

		$summary = ( new ImportCommentPersister( $this->store, $this->posts, $this->comments ) )->advance( $session );

		$this->assertSame( 2, $this->comments->count_comments() );
		$this->assertSame( 1, $summary['created'] );
		$this->assertSame( 1, $summary['updated'] );
		$this->assertSame( '1000', $this->store->find_idempotency_record( $session->get_id(), 'comment:remote:post:31:101' )->get_resource_id() );
		$this->assertSame( 1000, $this->comments->get_comment( 1001 )['comment_parent'] );
	}

	/**
	 * Comments wait for their imported post instead of failing irrecoverably.
	 *
	 * @return void
	 */
	public function test_persister_defers_comments_until_post_exists() {
		$session  = ImportSession::start_for_source( 'https://source.example.test/' );
		$document = $this->document_with_comments( $session );

		$this->store->save( $session );
		$this->store->save_prepared_document( $document );

		$summary = ( new ImportCommentPersister( $this->store, $this->posts, $this->comments ) )->advance( $session );
		$events  = $this->event_types( $session );

		$this->assertSame( 2, $summary['deferred'] );
		$this->assertSame( 0, $summary['failed'] );
		$this->assertSame( 0, $this->comments->count_comments() );
		$this->assertContains( 'comment.deferred_post_missing', $events );
	}

	/**
	 * Comment write failures are recorded as actionable progress events.
	 *
	 * @return void
	 */
	public function test_persister_records_comment_write_failures() {
		$session  = ImportSession::start_for_source( 'https://source.example.test/' );
		$document = $this->document_with_comments( $session );

		$this->store->save( $session );
		$this->store->save_prepared_document( $document );
		$post_id = $this->posts->insert_or_update( $document );
		$this->store->remember_idempotency_record(
			$session->get_id(),
			new ImportIdempotencyRecord( 'post:remote:post:31', 'post', (string) $post_id, $document->get_content_hash() )
		);
		$this->comments->fail_writes_with( 'WordPress rejected the comment.' );

		$summary = ( new ImportCommentPersister( $this->store, $this->posts, $this->comments ) )->advance( $session );
		$events  = $this->event_types( $session );

		$this->assertSame( 1, $summary['failed'] );
		$this->assertSame( 1, $summary['deferred'] );
		$this->assertSame( 0, $this->comments->count_comments() );
		$this->assertContains( 'comment.failed', $events );
	}

	/**
	 * Builds a prepared document fixture with staged REST comments.
	 *
	 * @param ImportSession $session Session.
	 * @return ImportPreparedDocument
	 */
	private function document_with_comments( ImportSession $session ) {
		return $this->document_with_remote_comment_key( $session, 'remote:post:31', 101, 102 );
	}

	/**
	 * Builds a prepared document fixture with staged REST comments and a custom source key.
	 *
	 * @param ImportSession $session           Session.
	 * @param string        $source_item_key   Source item key.
	 * @param int           $remote_comment_id Remote top-level comment id.
	 * @param int|null      $remote_child_id   Optional child comment id.
	 * @return ImportPreparedDocument
	 */
	private function document_with_remote_comment_key( ImportSession $session, $source_item_key, $remote_comment_id, $remote_child_id = null ) {
		$comments = array(
			array(
				'remote_comment_id' => (int) $remote_comment_id,
				'remote_parent_id'  => 0,
				'author_name'       => 'First Reader',
				'author_url'        => 'https://reader.example.test/',
				'content'           => '<p>First comment.</p>',
				'date'              => '2025-01-01T10:00:00',
				'date_gmt'          => '2025-01-01T10:00:00',
				'status'            => 'approved',
				'type'              => 'comment',
			),
		);

		if ( null !== $remote_child_id ) {
			$comments[] = array(
				'remote_comment_id' => (int) $remote_child_id,
				'remote_parent_id'  => (int) $remote_comment_id,
				'author_name'       => 'Reply Reader',
				'content'           => '<p>Nested reply.</p>',
				'date_gmt'          => '2025-01-01T11:00:00',
			);
		}

		return new ImportPreparedDocument(
			$session->get_id(),
			(string) $source_item_key,
			'wp-rest',
			'Commented Story',
			'<!-- wp:paragraph --><p>Story body.</p><!-- /wp:paragraph -->',
			1,
			'hash-story',
			array(
				'relative_path'   => 'commented-story',
				'remote_comments' => $comments,
			)
		);
	}

	/**
	 * Returns recent event types.
	 *
	 * @param ImportSession $session Session.
	 * @return array<int,string>
	 */
	private function event_types( ImportSession $session ) {
		return array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 20 )
		);
	}
}
