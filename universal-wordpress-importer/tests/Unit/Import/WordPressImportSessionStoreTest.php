<?php
/**
 * Tests for the WordPress-backed import session store.
 *
 * @package UniversalImporter\Tests\Unit\Import
 */

namespace UniversalImporter\Tests\Unit\Import;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use UniversalImporter\Import\ImportCheckpoint;
use UniversalImporter\Import\ImportDecision;
use UniversalImporter\Import\ImportIdempotencyRecord;
use UniversalImporter\Import\ImportMediaReference;
use UniversalImporter\Import\ImportPreparedDocument;
use UniversalImporter\Import\ImportProgress;
use UniversalImporter\Import\ImportProgressEvent;
use UniversalImporter\Import\ImportSession;
use UniversalImporter\Import\ImportSourceItem;
use UniversalImporter\Import\WordPressImportSessionSchema;
use UniversalImporter\Import\WordPressImportSessionStore;

/**
 * Covers durable session persistence and resume metadata behavior.
 */
final class WordPressImportSessionStoreTest extends TestCase {
	/**
	 * Fake database object.
	 *
	 * @var FakeWpdb
	 */
	private $wpdb;

	/**
	 * Mutable fake unix timestamp.
	 *
	 * @var int
	 */
	private $now;

	/**
	 * Store under test.
	 *
	 * @var WordPressImportSessionStore
	 */
	private $store;

	/**
	 * Sets up a fake store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->wpdb = new FakeWpdb();
		$this->now  = 1000;

		$this->store = new WordPressImportSessionStore(
			$this->wpdb,
			null,
			function () {
				return $this->now;
			}
		);
	}

	/**
	 * Session snapshots are persisted and updated without losing resume state.
	 *
	 * @return void
	 */
	public function test_save_and_find_round_trips_session_snapshot() {
		$session = ImportSession::start_for_source( 'zip://archive.zip#book.md', true );
		$this->store->save( $session );

		$updated = $session
			->mark_running()
			->with_progress( new ImportProgress( 5, 2, 1 ) )
			->with_checkpoint( new ImportCheckpoint( '/book.md:128', 2 ) );

		$this->store->save( $updated );

		$restored = $this->store->find( $session->get_id() );
		$rows     = $this->wpdb->get_table_rows( WordPressImportSessionSchema::get_table_names_for_prefix( $this->wpdb->prefix )['sessions'] );

		$this->assertNotNull( $restored );
		$this->assertSame( $updated->to_array(), $restored->to_array() );
		$this->assertTrue( $restored->is_dry_run() );
		$this->assertSame( 1, $rows[0]['dry_run'] );
	}

	/**
	 * Runnable sessions can be listed oldest first for continuation workers.
	 *
	 * @return void
	 */
	public function test_list_sessions_by_statuses_filters_and_limits_results() {
		$pending = ImportSession::start_for_source( 'local://pending.md' );
		$this->store->save( $pending );

		++$this->now;
		$running = ImportSession::start_for_source( 'local://running.md' )->mark_running();
		$this->store->save( $running );

		++$this->now;
		$paused = ImportSession::start_for_source( 'local://paused.md' )->mark_paused();
		$this->store->save( $paused );

		$sessions = $this->store->list_sessions_by_statuses(
			array(
				ImportSession::STATUS_RUNNING,
				ImportSession::STATUS_PENDING,
			),
			1
		);

		$this->assertCount( 1, $sessions );
		$this->assertSame( $pending->get_id()->to_string(), $sessions[0]->get_id()->to_string() );
	}

	/**
	 * Locks prevent concurrent workers but can expire or be released by token.
	 *
	 * @return void
	 */
	public function test_locks_block_competing_workers_until_expiry_or_release() {
		$session = ImportSession::start_for_source( 'local://book.md' );
		$this->store->save( $session );

		$lock = $this->store->acquire_lock( $session->get_id(), 'worker-a', 60 );

		$this->assertNotNull( $lock );
		$this->assertSame( 'worker-a', $lock->get_owner() );
		$this->assertNull( $this->store->acquire_lock( $session->get_id(), 'worker-b', 60 ) );

		$this->now  += 61;
		$replacement = $this->store->acquire_lock( $session->get_id(), 'worker-b', 60 );

		$this->assertNotNull( $replacement );
		$this->assertFalse( $this->store->release_lock( $lock ) );
		$this->assertTrue( $this->store->release_lock( $replacement ) );
		$this->assertNotNull( $this->store->acquire_lock( $session->get_id(), 'worker-c', 60 ) );
	}

	/**
	 * Lock refresh extends ownership and invalidates the previous token.
	 *
	 * @return void
	 */
	public function test_refresh_lock_extends_ownership_and_rotates_token() {
		$session = ImportSession::start_for_source( 'local://book.md' );
		$this->store->save( $session );

		$lock = $this->store->acquire_lock( $session->get_id(), 'worker-a', 10 );

		$this->assertNotNull( $lock );

		$this->now += 8;

		$refreshed = $this->store->refresh_lock( $lock, 60 );

		$this->assertNotNull( $refreshed );
		$this->assertSame( $lock->get_session_id()->to_string(), $refreshed->get_session_id()->to_string() );
		$this->assertSame( $lock->get_owner(), $refreshed->get_owner() );
		$this->assertNotSame( $lock->get_token(), $refreshed->get_token() );
		$this->assertFalse( $this->store->release_lock( $lock ) );

		$this->now += 20;

		$this->assertNull( $this->store->acquire_lock( $session->get_id(), 'worker-b', 60 ) );
		$this->assertTrue( $this->store->release_lock( $refreshed ) );
		$this->assertNotNull( $this->store->acquire_lock( $session->get_id(), 'worker-b', 60 ) );
	}

	/**
	 * Idempotency records can be read and updated by deterministic key.
	 *
	 * @return void
	 */
	public function test_idempotency_records_are_upserted_by_key() {
		$session = ImportSession::start_for_source( 'local://book.md' );
		$this->store->save( $session );

		$this->store->remember_idempotency_record(
			$session->get_id(),
			new ImportIdempotencyRecord( 'post:/chapter-1.md', 'post', '123', 'hash-a' )
		);

		$this->store->remember_idempotency_record(
			$session->get_id(),
			new ImportIdempotencyRecord( 'post:/chapter-1.md', 'post', '456', 'hash-b' )
		);

		$record = $this->store->find_idempotency_record( $session->get_id(), 'post:/chapter-1.md' );

		$this->assertNotNull( $record );
		$this->assertSame(
			array(
				'key'           => 'post:/chapter-1.md',
				'resource_type' => 'post',
				'resource_id'   => '456',
				'payload_hash'  => 'hash-b',
			),
			$record->to_array()
		);
	}

	/**
	 * Decisions move from pending to resolved without losing the original prompt.
	 *
	 * @return void
	 */
	public function test_decisions_can_be_listed_and_resolved() {
		$session = ImportSession::start_for_source( 'https://example.com/wp-json' );
		$this->store->save( $session );

		$this->store->save_decision(
			$session->get_id(),
			ImportDecision::pending(
				'confirm-first-party-domains',
				'Confirm first-party domains before URL rewriting.',
				array( 'domains' => array( 'example.com', 'www.example.com' ) )
			)
		);

		$pending = $this->store->list_pending_decisions( $session->get_id() );

		$this->assertCount( 1, $pending );
		$this->assertSame( 'confirm-first-party-domains', $pending[0]->get_key() );

		$this->store->resolve_decision(
			$session->get_id(),
			'confirm-first-party-domains',
			array( 'confirmed_domains' => array( 'example.com' ) )
		);

		$this->assertSame( array(), $this->store->list_pending_decisions( $session->get_id() ) );

		$resolved      = $this->store->find_decision( $session->get_id(), 'confirm-first-party-domains' );
		$resolved_list = $this->store->list_resolved_decisions( $session->get_id() );

		$this->assertNotNull( $resolved );
		$this->assertSame( ImportDecision::STATUS_RESOLVED, $resolved->get_status() );
		$this->assertSame( array( 'confirmed_domains' => array( 'example.com' ) ), $resolved->get_answer() );
		$this->assertCount( 1, $resolved_list );
		$this->assertSame( 'confirm-first-party-domains', $resolved_list[0]->get_key() );
	}

	/**
	 * Unapplied resolved decisions are filtered before applying the limit.
	 *
	 * @return void
	 */
	public function test_unapplied_resolved_decisions_by_key_prefix_skip_unrelated_and_applied_rows() {
		$session = ImportSession::start_for_source( 'https://example.com/wp-json' );
		$this->store->save( $session );

		$this->store->save_decision(
			$session->get_id(),
			ImportDecision::pending(
				'confirm-first-party-domains',
				'Confirm first-party domains.',
				array()
			)->resolve( array( 'confirmed_domains' => array( 'example.com' ) ) )
		);

		foreach ( array( 'applied', 'first', 'second' ) as $key ) {
			$this->store->save_decision(
				$session->get_id(),
				ImportDecision::pending(
					'map-rest-relationships:remote:' . $key,
					'Map remote relationships.',
					array( 'source_item_key' => 'remote:' . $key )
				)->resolve( array( 'author' => array( 'local_user_id' => 77 ) ) )
			);
		}

		$this->store->remember_idempotency_record(
			$session->get_id(),
			new ImportIdempotencyRecord(
				'relationship-mapping:map-rest-relationships:remote:applied',
				'relationship-mapping',
				'1',
				'hash-applied'
			)
		);

		$first_page = $this->store->list_unapplied_resolved_decisions_by_key_prefix(
			$session->get_id(),
			'map-rest-relationships:',
			'relationship-mapping:',
			1
		);
		$two_rows   = $this->store->list_unapplied_resolved_decisions_by_key_prefix(
			$session->get_id(),
			'map-rest-relationships:',
			'relationship-mapping:',
			5
		);

		$this->assertCount( 1, $first_page );
		$this->assertSame( 'map-rest-relationships:remote:first', $first_page[0]->get_key() );
		$this->assertSame(
			array(
				'map-rest-relationships:remote:first',
				'map-rest-relationships:remote:second',
			),
			array_map(
				function ( ImportDecision $decision ) {
					return $decision->get_key();
				},
				$two_rows
			)
		);
	}

	/**
	 * Progress events are persisted with timestamps and listed newest first.
	 *
	 * @return void
	 */
	public function test_progress_events_are_listed_newest_first() {
		$session = ImportSession::start_for_source( 'local://book.md' );
		$this->store->save( $session );

		$first = $this->store->record_event(
			$session->get_id(),
			new ImportProgressEvent(
				ImportProgressEvent::LEVEL_INFO,
				'session.started',
				'Import session started.',
				array( 'source' => 'local://book.md' )
			)
		);

		++$this->now;

		$this->store->record_event(
			$session->get_id(),
			new ImportProgressEvent(
				ImportProgressEvent::LEVEL_WARNING,
				'item.skipped',
				'Skipped an unsupported item.',
				array( 'path' => '/draft.tmp' )
			)
		);

		$events = $this->store->list_events( $session->get_id(), 10 );

		$this->assertCount( 2, $events );
		$this->assertSame( 'item.skipped', $events[0]->get_type() );
		$this->assertSame( 'session.started', $events[1]->get_type() );
		$this->assertSame( '1970-01-01 00:16:40', $first->get_created_at() );
	}

	/**
	 * Source items are upserted and listed oldest first for resumable traversal.
	 *
	 * @return void
	 */
	public function test_source_items_are_upserted_listed_and_counted_by_status() {
		$session = ImportSession::start_for_source( '/tmp/import-root' );
		$this->store->save( $session );

		$directory = ImportSourceItem::queued(
			$session->get_id(),
			'local:root',
			null,
			'/tmp/import-root',
			'',
			ImportSourceItem::TYPE_DIRECTORY
		);

		$this->store->save_source_item( $directory );

		++$this->now;

		$file = ImportSourceItem::queued(
			$session->get_id(),
			'local:file',
			'local:root',
			'/tmp/import-root/chapter.md',
			'chapter.md',
			ImportSourceItem::TYPE_FILE,
			array( 'extension' => 'md' )
		);

		$this->store->save_source_item( $file );
		$this->store->save_source_item( $file->with_status( ImportSourceItem::STATUS_DISCOVERED ) );

		$queued = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_QUEUED ), 5 );
		$recent = $this->store->list_recent_source_items( $session->get_id(), 2 );

		$this->assertCount( 1, $queued );
		$this->assertSame( 'local:root', $queued[0]->get_key() );
		$this->assertSame( array( 'local:file', 'local:root' ), array( $recent[0]->get_key(), $recent[1]->get_key() ) );
		$this->assertSame( 'chapter.md', $this->store->find_source_item( $session->get_id(), 'local:file' )->get_relative_path() );
		$this->assertSame( 2, $this->store->count_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_QUEUED, ImportSourceItem::STATUS_DISCOVERED ) ) );
		$this->assertSame( 1, $this->store->count_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_DISCOVERED ) ) );
	}

	/**
	 * Invalid UTF-8 bytes in operational metadata are substituted instead of breaking persistence.
	 *
	 * @return void
	 */
	public function test_source_item_metadata_substitutes_invalid_utf8() {
		$session = ImportSession::start_for_source( '/tmp/import-root/binary.pdf' );
		$this->store->save( $session );

		$item = ImportSourceItem::queued(
			$session->get_id(),
			'local:binary',
			null,
			'/tmp/import-root/binary.pdf',
			'binary.pdf',
			ImportSourceItem::TYPE_FILE,
			array(
				'extension'  => 'pdf',
				'diagnostic' => "valid\xffbytes",
			)
		);

		$this->store->save_source_item( $item );

		$restored = $this->store->find_source_item( $session->get_id(), 'local:binary' );

		$this->assertNotNull( $restored );
		$this->assertSame( 'pdf', $restored->get_metadata()['extension'] );
		$this->assertStringContainsString( "\xef\xbf\xbd", $restored->get_metadata()['diagnostic'] );
	}

	/**
	 * Prepared documents are stored separately from source item metadata and upsert by source item.
	 *
	 * @return void
	 */
	public function test_prepared_documents_are_upserted_by_source_item_key() {
		$session = ImportSession::start_for_source( '/tmp/import-root/chapter.md' );
		$this->store->save( $session );

		$document = new ImportPreparedDocument(
			$session->get_id(),
			'local:chapter',
			'markdown',
			'Chapter',
			'<!-- wp:paragraph --><p>One</p><!-- /wp:paragraph -->',
			1,
			'hash-a',
			array( 'relative_path' => 'chapter.md' )
		);

		$this->store->save_prepared_document( $document );
		$this->store->save_prepared_document(
			new ImportPreparedDocument(
				$session->get_id(),
				'local:chapter',
				'markdown',
				'Chapter revised',
				'<!-- wp:paragraph --><p>Two</p><!-- /wp:paragraph -->',
				1,
				'hash-b',
				array( 'relative_path' => 'chapter.md' )
			)
		);

		$restored = $this->store->find_prepared_document( $session->get_id(), 'local:chapter' );

		$this->assertNotNull( $restored );
		$this->assertSame( 'Chapter revised', $restored->get_title() );
		$this->assertSame( 'hash-b', $restored->get_content_hash() );
		$this->assertStringContainsString( '<p>Two</p>', $restored->get_block_markup() );
		$this->assertSame( array( 'relative_path' => 'chapter.md' ), $restored->get_metadata() );
		$this->assertSame( 1, $this->store->count_prepared_documents( $session->get_id() ) );
		$this->assertSame( 'Chapter revised', $this->store->list_recent_prepared_documents( $session->get_id(), 1 )[0]->get_title() );
	}

	/**
	 * Prepared documents can be listed with a stable keyset cursor for full-session scans.
	 *
	 * @return void
	 */
	public function test_prepared_documents_can_be_listed_after_source_item_key() {
		$session = ImportSession::start_for_source( '/tmp/import-root/book.epub' );
		$this->store->save( $session );

		for ( $index = 1; $index <= 3; ++$index ) {
			$this->store->save_prepared_document(
				new ImportPreparedDocument(
					$session->get_id(),
					'epub:chapter-' . $index,
					'epub',
					'Chapter ' . $index,
					'<!-- wp:paragraph --><p>Chapter ' . $index . '</p><!-- /wp:paragraph -->',
					1,
					'hash-' . $index,
					array( 'epub_spine_index' => $index - 1 )
				)
			);
		}

		$first_page  = $this->store->list_prepared_documents_after_source_item_key( $session->get_id(), null, 2 );
		$second_page = $this->store->list_prepared_documents_after_source_item_key(
			$session->get_id(),
			$first_page[1]->get_source_item_key(),
			2
		);

		$this->assertCount( 2, $first_page );
		$this->assertSame( 'epub:chapter-1', $first_page[0]->get_source_item_key() );
		$this->assertSame( 'epub:chapter-2', $first_page[1]->get_source_item_key() );
		$this->assertCount( 1, $second_page );
		$this->assertSame( 'epub:chapter-3', $second_page[0]->get_source_item_key() );
	}

	/**
	 * Source items can be listed by status with a stable item-key cursor.
	 *
	 * @return void
	 */
	public function test_source_items_can_be_listed_by_status_after_item_key() {
		$session = ImportSession::start_for_source( '/tmp/import-root/export.xml' );
		$this->store->save( $session );

		foreach ( array( 'source:item:003', 'source:item:001', 'source:item:002' ) as $key ) {
			$this->store->save_source_item(
				ImportSourceItem::queued(
					$session->get_id(),
					$key,
					null,
					'/tmp/import-root/export.xml',
					basename( $key ),
					ImportSourceItem::TYPE_FILE
				)->with_status( ImportSourceItem::STATUS_IMPORTED )
			);
		}

		$first_page  = $this->store->list_source_items_by_statuses_after_item_key( $session->get_id(), array( ImportSourceItem::STATUS_IMPORTED ), null, 2 );
		$second_page = $this->store->list_source_items_by_statuses_after_item_key(
			$session->get_id(),
			array( ImportSourceItem::STATUS_IMPORTED ),
			$first_page[1]->get_key(),
			2
		);

		$this->assertCount( 2, $first_page );
		$this->assertSame( 'source:item:001', $first_page[0]->get_key() );
		$this->assertSame( 'source:item:002', $first_page[1]->get_key() );
		$this->assertCount( 1, $second_page );
		$this->assertSame( 'source:item:003', $second_page[0]->get_key() );
	}

	/**
	 * Media references are upserted and counted independently from source items.
	 *
	 * @return void
	 */
	public function test_media_references_are_upserted_listed_and_counted_by_status() {
		$session = ImportSession::start_for_source( '/tmp/import-root/chapter.md' );
		$this->store->save( $session );

		$reference = ImportMediaReference::queued(
			$session->get_id(),
			'media:one',
			'local:chapter',
			'images/photo.jpg',
			'/tmp/import-root/images/photo.jpg',
			ImportMediaReference::TYPE_IMAGE,
			array( 'reference_scope' => 'local-relative-path' )
		);

		$this->store->save_media_reference( $reference );
		++$this->now;
		$this->store->save_media_reference(
			ImportMediaReference::queued(
				$session->get_id(),
				'media:one',
				'local:chapter',
				'images/photo-large.jpg',
				'/tmp/import-root/images/photo-large.jpg',
				ImportMediaReference::TYPE_IMAGE,
				array( 'reference_scope' => 'local-relative-path' )
			)
		);

		$restored = $this->store->find_media_reference( $session->get_id(), 'media:one' );
		$queued   = $this->store->list_media_references_by_statuses( $session->get_id(), array( ImportMediaReference::STATUS_QUEUED ), 5 );
		$recent   = $this->store->list_recent_media_references( $session->get_id(), 1 );

		$this->assertNotNull( $restored );
		$this->assertSame( 'images/photo-large.jpg', $restored->get_original_url() );
		$this->assertSame( '/tmp/import-root/images/photo-large.jpg', $restored->get_resolved_source_uri() );
		$this->assertCount( 1, $queued );
		$this->assertSame( 'media:one', $recent[0]->get_key() );
		$this->assertSame( 1, $this->store->count_media_references_by_statuses( $session->get_id(), array( ImportMediaReference::STATUS_QUEUED ) ) );
		$this->assertSame( 0, $this->store->count_media_references_by_statuses( $session->get_id(), array( ImportMediaReference::STATUS_FAILED ) ) );
	}

	/**
	 * Media references can be listed with a stable reference-key cursor.
	 *
	 * @return void
	 */
	public function test_media_references_can_be_listed_after_reference_key() {
		$session = ImportSession::start_for_source( '/tmp/import-root/chapter.md' );
		$this->store->save( $session );

		foreach ( array( 'media:003', 'media:001', 'media:002' ) as $key ) {
			$this->store->save_media_reference(
				ImportMediaReference::queued(
					$session->get_id(),
					$key,
					'local:chapter',
					'https://source.example.test/' . $key . '.jpg',
					'https://source.example.test/' . $key . '.jpg',
					ImportMediaReference::TYPE_IMAGE,
					array( 'reference_scope' => 'candidate-first-party' )
				)
			);
			++$this->now;
		}

		$first_page  = $this->store->list_media_references_by_statuses_after_reference_key(
			$session->get_id(),
			array( ImportMediaReference::STATUS_QUEUED ),
			null,
			2
		);
		$second_page = $this->store->list_media_references_by_statuses_after_reference_key(
			$session->get_id(),
			array( ImportMediaReference::STATUS_QUEUED ),
			$first_page[1]->get_key(),
			2
		);

		$this->assertCount( 2, $first_page );
		$this->assertSame( 'media:001', $first_page[0]->get_key() );
		$this->assertSame( 'media:002', $first_page[1]->get_key() );
		$this->assertCount( 1, $second_page );
		$this->assertSame( 'media:003', $second_page[0]->get_key() );
	}

	/**
	 * Idempotency records can be counted by resource type for browser progress.
	 *
	 * @return void
	 */
	public function test_idempotency_records_can_be_counted_by_resource_type() {
		$session = ImportSession::start_for_source( 'local://book.md' );
		$this->store->save( $session );

		$this->store->remember_idempotency_record(
			$session->get_id(),
			new ImportIdempotencyRecord( 'post:chapter', 'post', '123', 'hash-a' )
		);
		$this->store->remember_idempotency_record(
			$session->get_id(),
			new ImportIdempotencyRecord( 'media:image', 'attachment', '456', 'hash-b' )
		);

		$this->assertSame( 1, $this->store->count_idempotency_records_by_resource_type( $session->get_id(), 'post' ) );
		$this->assertSame( 1, $this->store->count_idempotency_records_by_resource_type( $session->get_id(), 'attachment' ) );
	}

	/**
	 * Malformed stored JSON produces an actionable diagnostic.
	 *
	 * @return void
	 */
	public function test_malformed_stored_json_throws_diagnostic_exception() {
		$session = ImportSession::start_for_source( 'local://book.md' );
		$this->store->save( $session );

		$tables = WordPressImportSessionSchema::get_table_names_for_prefix( $this->wpdb->prefix );
		$this->wpdb->set_row_value(
			$tables['sessions'],
			array( 'id' => $session->get_id()->to_string() ),
			'progress_json',
			'{not-json'
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'progress_json' );

		$this->store->find( $session->get_id() );
	}
}
