<?php
/**
 * Tests for prepared document post persistence.
 *
 * @package UniversalImporter\Tests\Unit\Import
 */

namespace UniversalImporter\Tests\Unit\Import;

use PHPUnit\Framework\TestCase;
use UniversalImporter\Import\ImportDecision;
use UniversalImporter\Import\ImportPostPersister;
use UniversalImporter\Import\ImportPreparedDocument;
use UniversalImporter\Import\ImportRelationshipMappingApplier;
use UniversalImporter\Import\ImportRelationshipMappingDecision;
use UniversalImporter\Import\ImportRunnerControls;
use UniversalImporter\Import\ImportSession;
use UniversalImporter\Import\ImportSimulatedCrashException;
use UniversalImporter\Import\WordPressImportSessionStore;

/**
 * Covers idempotent prepared document persistence without WordPress loaded.
 */
final class ImportPostPersisterTest extends TestCase {
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
	 * Sets up test dependencies.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->wpdb  = new FakeWpdb();
		$this->store = new WordPressImportSessionStore( $this->wpdb );
		$this->posts = new FakePostGateway();
	}

	/**
	 * Prepared documents are published as pages and remembered idempotently.
	 *
	 * @return void
	 */
	public function test_persister_publishes_page_for_prepared_document() {
		$session  = ImportSession::start_for_source( '/tmp/book/chapter.md' );
		$document = $this->document( $session, 'local:chapter', 'Chapter', 'hash-a' );

		$this->store->save( $session );
		$this->store->save_prepared_document( $document );

		$summary = ( new ImportPostPersister( $this->store, $this->posts ) )->advance( $session );
		$record  = $this->store->find_idempotency_record( $session->get_id(), 'post:local:chapter' );
		$events  = $this->store->list_events( $session->get_id(), 1 );

		$this->assertSame(
			array(
				'created' => 1,
				'updated' => 0,
				'skipped' => 0,
				'failed'  => 0,
				'message' => 'Prepared document post persistence inspected staged documents.',
			),
			$summary
		);
		$this->assertSame( 1, $this->posts->count_posts() );
		$this->assertSame( 'Chapter', $this->posts->get_post( 1 )['post_title'] );
		$this->assertSame( 'publish', $this->posts->get_post( 1 )['post_status'] );
		$this->assertNotNull( $record );
		$this->assertSame( 'post', $record->get_resource_type() );
		$this->assertSame( '1', $record->get_resource_id() );
		$this->assertSame( 'hash-a', $record->get_payload_hash() );
		$this->assertSame( 'post.created', $events[0]->get_type() );
	}

	/**
	 * A resolved import post status decision can keep imported pages as drafts.
	 *
	 * @return void
	 */
	public function test_persister_can_create_draft_page_from_post_status_decision() {
		$session  = ImportSession::start_for_source( '/tmp/book/chapter.md' );
		$document = $this->document( $session, 'local:chapter', 'Chapter', 'hash-a' );

		$this->store->save( $session );
		$this->store->save_prepared_document( $document );
		$this->store->save_decision(
			$session->get_id(),
			ImportDecision::pending(
				ImportPostPersister::POST_STATUS_DECISION_KEY,
				'Choose whether imported pages should be published or saved as drafts.',
				array()
			)->resolve( array( 'post_status' => 'draft' ) )
		);

		( new ImportPostPersister( $this->store, $this->posts ) )->advance( $session );

		$this->assertSame( 'draft', $this->posts->get_post( 1 )['post_status'] );
	}

	/**
	 * Re-running the persister does not create duplicate posts for unchanged documents.
	 *
	 * @return void
	 */
	public function test_persister_skips_unchanged_document_with_matching_idempotency_record() {
		$session  = ImportSession::start_for_source( '/tmp/book/chapter.md' );
		$document = $this->document( $session, 'local:chapter', 'Chapter', 'hash-a' );

		$this->store->save( $session );
		$this->store->save_prepared_document( $document );

		$persister = new ImportPostPersister( $this->store, $this->posts );
		$persister->advance( $session );
		$summary = $persister->advance( $session );

		$this->assertSame( 1, $this->posts->count_posts() );
		$this->assertSame( 1, $summary['skipped'] );
		$this->assertSame( 0, $summary['created'] );
		$this->assertSame( 0, $summary['updated'] );
	}

	/**
	 * Changed prepared content updates the remembered post instead of duplicating it.
	 *
	 * @return void
	 */
	public function test_persister_updates_existing_post_when_document_hash_changes() {
		$session = ImportSession::start_for_source( '/tmp/book/chapter.md' );
		$this->store->save( $session );
		$this->store->save_prepared_document( $this->document( $session, 'local:chapter', 'Chapter', 'hash-a' ) );

		$persister = new ImportPostPersister( $this->store, $this->posts );
		$persister->advance( $session );

		$this->store->save_prepared_document( $this->document( $session, 'local:chapter', 'Chapter Revised', 'hash-b' ) );
		$summary = $persister->advance( $session );
		$record  = $this->store->find_idempotency_record( $session->get_id(), 'post:local:chapter' );
		$events  = $this->store->list_events( $session->get_id(), 1 );

		$this->assertSame( 1, $this->posts->count_posts() );
		$this->assertSame( 0, $summary['created'] );
		$this->assertSame( 1, $summary['updated'] );
		$this->assertSame( 'Chapter Revised', $this->posts->get_post( 1 )['post_title'] );
		$this->assertSame( 'hash-b', $record->get_payload_hash() );
		$this->assertSame( 'post.updated', $events[0]->get_type() );
	}

	/**
	 * Existing posts discovered by importer metadata are reused after an interruption before idempotency is written.
	 *
	 * @return void
	 */
	public function test_persister_reuses_existing_post_found_by_gateway_metadata() {
		$session  = ImportSession::start_for_source( '/tmp/book/chapter.md' );
		$document = $this->document( $session, 'local:chapter', 'Chapter', 'hash-a' );

		$this->store->save( $session );
		$this->store->save_prepared_document( $document );
		$this->posts->insert_or_update( $document );

		$summary = ( new ImportPostPersister( $this->store, $this->posts ) )->advance( $session );
		$record  = $this->store->find_idempotency_record( $session->get_id(), 'post:local:chapter' );

		$this->assertSame( 1, $this->posts->count_posts() );
		$this->assertSame( 0, $summary['created'] );
		$this->assertSame( 1, $summary['updated'] );
		$this->assertSame( '1', $record->get_resource_id() );
	}

	/**
	 * A crash after the WordPress write but before idempotency is recovered by metadata lookup.
	 *
	 * @return void
	 */
	public function test_persister_recovers_after_post_write_idempotency_gap() {
		$session  = ImportSession::start_for_source( '/tmp/book/chapter.md' );
		$document = $this->document( $session, 'local:chapter', 'Chapter', 'hash-a' );

		$this->store->save( $session );
		$this->store->save_prepared_document( $document );

		try {
			( new ImportPostPersister( $this->store, $this->posts, new ImportRunnerControls( false, false, 0, null, true ) ) )->advance( $session );
			$this->fail( 'Expected a controlled crash after the post write.' );
		} catch ( ImportSimulatedCrashException $exception ) {
			$this->assertSame( 'Simulated importer crash after post write and before idempotency record.', $exception->getMessage() );
		}

		$this->assertSame( 1, $this->posts->count_posts() );
		$this->assertNull( $this->store->find_idempotency_record( $session->get_id(), 'post:local:chapter' ) );
		$this->assertSame( 'post.simulated_crash_after_write', $this->store->list_events( $session->get_id(), 1 )[0]->get_type() );

		$summary = ( new ImportPostPersister( $this->store, $this->posts ) )->advance( $session );
		$record  = $this->store->find_idempotency_record( $session->get_id(), 'post:local:chapter' );

		$this->assertSame( 1, $this->posts->count_posts() );
		$this->assertSame( 0, $summary['created'] );
		$this->assertSame( 1, $summary['updated'] );
		$this->assertSame( '1', $record->get_resource_id() );
		$this->assertSame( 'hash-a', $record->get_payload_hash() );
	}

	/**
	 * Post write failures are recorded as actionable progress events.
	 *
	 * @return void
	 */
	public function test_persister_records_post_write_failures() {
		$session = ImportSession::start_for_source( '/tmp/book/chapter.md' );
		$this->store->save( $session );
		$this->store->save_prepared_document( $this->document( $session, 'local:chapter', 'Chapter', 'hash-a' ) );
		$this->posts->fail_writes_with( 'WordPress rejected the draft.' );

		$summary = ( new ImportPostPersister( $this->store, $this->posts ) )->advance( $session );
		$events  = $this->store->list_events( $session->get_id(), 1 );

		$this->assertSame( 1, $summary['failed'] );
		$this->assertNull( $this->store->find_idempotency_record( $session->get_id(), 'post:local:chapter' ) );
		$this->assertSame( 'post.failed', $events[0]->get_type() );
		$this->assertSame( 'WordPress rejected the draft.', $events[0]->get_message() );
	}

	/**
	 * REST relationship metadata is persisted onto the draft post before idempotency is recorded.
	 *
	 * @return void
	 */
	public function test_persister_applies_staged_rest_author_and_terms_to_draft() {
		$session = ImportSession::start_for_source( 'https://source.example.test/' );
		$this->store->save( $session );
		$this->posts->add_user( 'ada-editor', 42 );
		$this->store->save_prepared_document(
			$this->document(
				$session,
				'remote:post:31',
				'Relationship Story',
				'hash-rest',
				array(
					'remote_author' => array(
						'id'   => 9,
						'name' => 'Ada Editor',
						'slug' => 'ada-editor',
					),
					'remote_terms'  => array(
						'category' => array(
							array(
								'id'   => 4,
								'name' => 'Research',
								'slug' => 'research',
							),
						),
						'post_tag' => array(
							array(
								'id'   => 12,
								'name' => 'Migration',
								'slug' => 'migration',
							),
						),
					),
				)
			)
		);

		$summary = ( new ImportPostPersister( $this->store, $this->posts ) )->advance( $session );
		$post    = $this->posts->get_post( 1 );
		$events  = $this->store->list_events( $session->get_id(), 2 );
		$record  = $this->store->find_idempotency_record( $session->get_id(), 'post:remote:post:31' );

		$this->assertSame( 1, $summary['created'] );
		$this->assertSame( 42, $post['post_author'] );
		$this->assertNotEmpty( $post['terms']['category'] );
		$this->assertNotEmpty( $post['terms']['post_tag'] );
		$this->assertSame( 'post.created', $events[0]->get_type() );
		$this->assertSame( 'post.relationships_mapped', $events[1]->get_type() );
		$this->assertSame( 'hash-rest', $record->get_payload_hash() );
	}

	/**
	 * Missing local users or taxonomies keep the draft import recoverable and observable.
	 *
	 * @return void
	 */
	public function test_persister_records_partial_relationship_mapping_without_failing_post() {
		$session = ImportSession::start_for_source( 'https://source.example.test/' );
		$this->store->save( $session );
		$this->posts->remove_taxonomy( 'genre' );
		$this->store->save_prepared_document(
			$this->document(
				$session,
				'remote:book:7',
				'Remote Book',
				'hash-book',
				array(
					'remote_author' => array(
						'id'   => 3,
						'name' => 'Missing Author',
						'slug' => 'missing-author',
					),
					'remote_terms'  => array(
						'genre' => array(
							array(
								'id'   => 8,
								'name' => 'Memoir',
								'slug' => 'memoir',
							),
						),
					),
				)
			)
		);

		$summary  = ( new ImportPostPersister( $this->store, $this->posts ) )->advance( $session );
		$events   = $this->store->list_events( $session->get_id(), 2 );
		$record   = $this->store->find_idempotency_record( $session->get_id(), 'post:remote:book:7' );
		$decision = $this->store->find_decision(
			$session->get_id(),
			ImportRelationshipMappingDecision::decision_key( 'remote:book:7' )
		);

		$this->assertSame( 1, $summary['created'] );
		$this->assertSame( 'post.relationships_partially_mapped', $events[1]->get_type() );
		$this->assertSame( 'unmapped', $events[1]->get_context()['relationships']['author']['status'] );
		$this->assertSame( 'taxonomy_missing', $events[1]->get_context()['relationships']['terms']['genre']['status'] );
		$this->assertSame( 'hash-book', $record->get_payload_hash() );
		$this->assertNotNull( $decision );
		$this->assertSame( 'remote:book:7', $decision->get_options()['source_item_key'] );
		$this->assertSame( 1, $decision->get_options()['post_id'] );
		$this->assertSame( 'Missing Author', $decision->get_options()['remote_author']['name'] );
		$this->assertSame( 'memoir', $decision->get_options()['answer_template']['terms']['genre'][0]['remote_slug'] );
	}

	/**
	 * Resolved REST relationship mapping decisions are applied to already-imported drafts once.
	 *
	 * @return void
	 */
	public function test_relationship_mapping_applier_updates_imported_draft_from_resolved_decision() {
		$session      = ImportSession::start_for_source( 'https://source.example.test/' );
		$decision_key = ImportRelationshipMappingDecision::decision_key( 'remote:book:7' );

		$this->store->save( $session );
		$this->posts->remove_taxonomy( 'genre' );
		$this->store->save_prepared_document(
			$this->document(
				$session,
				'remote:book:7',
				'Remote Book',
				'hash-book',
				array(
					'remote_author' => array(
						'id'   => 3,
						'name' => 'Missing Author',
						'slug' => 'missing-author',
					),
					'remote_terms'  => array(
						'genre' => array(
							array(
								'id'   => 8,
								'name' => 'Memoir',
								'slug' => 'memoir',
							),
						),
					),
				)
			)
		);

		( new ImportPostPersister( $this->store, $this->posts ) )->advance( $session );
		$this->store->resolve_decision(
			$session->get_id(),
			$decision_key,
			array(
				'author' => array(
					'local_user_id' => 77,
				),
				'terms'  => array(
					'genre' => array(
						array(
							'remote_id'      => 8,
							'local_taxonomy' => 'category',
							'local_term_id'  => 501,
						),
					),
				),
			)
		);

		$applier = new ImportRelationshipMappingApplier( $this->store, $this->posts );
		$summary = $applier->advance( $session );
		$repeat  = $applier->advance( $session );
		$post    = $this->posts->get_post( 1 );
		$record  = $this->store->find_idempotency_record( $session->get_id(), 'relationship-mapping:' . $decision_key );
		$events  = $this->store->list_events( $session->get_id(), 1 );

		$this->assertSame( 1, $summary['applied'] );
		$this->assertSame( 0, $repeat['skipped'] );
		$this->assertSame( 77, $post['post_author'] );
		$this->assertSame( array( 501 ), $post['terms']['category'] );
		$this->assertNotNull( $record );
		$this->assertSame( 'relationship-mapping', $record->get_resource_type() );
		$this->assertSame( 'post.relationships_mapping_applied', $events[0]->get_type() );
		$this->assertSame( $decision_key, $events[0]->get_context()['decision_key'] );
	}

	/**
	 * Resolved relationship mapping decisions past the first page still apply.
	 *
	 * @return void
	 */
	public function test_relationship_mapping_applier_reaches_later_unapplied_decisions() {
		$session         = ImportSession::start_for_source( 'https://source.example.test/' );
		$first_document  = $this->document( $session, 'remote:book:1', 'Remote Book 1', 'hash-book-1' );
		$second_document = $this->document( $session, 'remote:book:2', 'Remote Book 2', 'hash-book-2' );
		$first_key       = ImportRelationshipMappingDecision::decision_key( 'remote:book:1' );
		$second_key      = ImportRelationshipMappingDecision::decision_key( 'remote:book:2' );

		$this->store->save( $session );
		$first_post_id  = $this->posts->insert_or_update( $first_document );
		$second_post_id = $this->posts->insert_or_update( $second_document );

		$this->store->save_decision(
			$session->get_id(),
			$this->resolved_relationship_decision( $first_key, $first_post_id, 'remote:book:1', 77 )
		);
		$this->store->save_decision(
			$session->get_id(),
			$this->resolved_relationship_decision( $second_key, $second_post_id, 'remote:book:2', 88 )
		);

		$applier = new ImportRelationshipMappingApplier( $this->store, $this->posts );
		$first   = $applier->advance( $session, 1 );
		$second  = $applier->advance( $session, 1 );

		$this->assertSame( 1, $first['applied'] );
		$this->assertSame( 1, $second['applied'] );
		$this->assertSame( 77, $this->posts->get_post( $first_post_id )['post_author'] );
		$this->assertSame( 88, $this->posts->get_post( $second_post_id )['post_author'] );
		$this->assertNotNull( $this->store->find_idempotency_record( $session->get_id(), 'relationship-mapping:' . $first_key ) );
		$this->assertNotNull( $this->store->find_idempotency_record( $session->get_id(), 'relationship-mapping:' . $second_key ) );
	}

	/**
	 * Relationship mapping failures are observable and do not poison idempotency.
	 *
	 * @return void
	 */
	public function test_relationship_mapping_applier_records_missing_post_failures() {
		$session = ImportSession::start_for_source( 'https://source.example.test/' );
		$this->store->save( $session );
		$this->store->save_decision(
			$session->get_id(),
			ImportDecision::pending(
				ImportRelationshipMappingDecision::decision_key( 'remote:missing:1' ),
				'Map the remote REST author and taxonomy relationships for imported draft post 999.',
				array(
					'post_id'         => 999,
					'source_item_key' => 'remote:missing:1',
				)
			)->resolve(
				array(
					'author' => array(
						'local_user_id' => 77,
					),
				)
			)
		);

		$summary = ( new ImportRelationshipMappingApplier( $this->store, $this->posts ) )->advance( $session );
		$events  = $this->store->list_events( $session->get_id(), 1 );

		$this->assertSame( 1, $summary['failed'] );
		$this->assertSame( 'post.relationships_mapping_failed', $events[0]->get_type() );
		$this->assertSame( 'Fake post does not exist for relationship mapping.', $events[0]->get_message() );
		$this->assertNull( $this->store->find_idempotency_record( $session->get_id(), 'relationship-mapping:' . ImportRelationshipMappingDecision::decision_key( 'remote:missing:1' ) ) );
	}

	/**
	 * Incomplete resolved mappings stay retryable instead of being marked idempotent.
	 *
	 * @return void
	 */
	public function test_relationship_mapping_applier_keeps_incomplete_answers_retryable() {
		$session      = ImportSession::start_for_source( 'https://source.example.test/' );
		$decision_key = ImportRelationshipMappingDecision::decision_key( 'remote:book:10' );
		$document     = $this->document( $session, 'remote:book:10', 'Remote Book', 'hash-book' );

		$this->store->save( $session );
		$this->posts->insert_or_update( $document );
		$this->store->save_decision(
			$session->get_id(),
			ImportDecision::pending(
				$decision_key,
				'Map the remote REST author and taxonomy relationships for imported draft post 1.',
				array(
					'post_id'         => 1,
					'source_item_key' => 'remote:book:10',
				)
			)->resolve(
				array(
					'author' => array(
						'local_user_id' => 0,
					),
				)
			)
		);

		$summary = ( new ImportRelationshipMappingApplier( $this->store, $this->posts ) )->advance( $session );
		$events  = $this->store->list_events( $session->get_id(), 1 );

		$this->assertSame( 1, $summary['failed'] );
		$this->assertSame( 'post.relationships_mapping_incomplete', $events[0]->get_type() );
		$this->assertSame( 'unmapped', $events[0]->get_context()['relationships']['author']['status'] );
		$this->assertNull( $this->store->find_idempotency_record( $session->get_id(), 'relationship-mapping:' . $decision_key ) );
	}

	/**
	 * Missing WordPress post APIs produce a friendly no-op diagnostic.
	 *
	 * @return void
	 */
	public function test_persister_reports_unavailable_post_gateway_without_failing() {
		$session = ImportSession::start_for_source( '/tmp/book/chapter.md' );
		$this->store->save( $session );
		$this->store->save_prepared_document( $this->document( $session, 'local:chapter', 'Chapter', 'hash-a' ) );
		$this->posts->make_unavailable();

		$summary = ( new ImportPostPersister( $this->store, $this->posts ) )->advance( $session );

		$this->assertSame( 0, $summary['created'] );
		$this->assertSame( 0, $summary['failed'] );
		$this->assertSame( 'Fake post gateway is unavailable.', $summary['message'] );
	}

	/**
	 * Builds a prepared document fixture.
	 *
	 * @param ImportSession $session         Session.
	 * @param string        $source_item_key Source item key.
	 * @param string        $title           Title.
	 * @param string        $hash            Content hash.
	 * @param array         $metadata        Additional metadata.
	 * @return ImportPreparedDocument
	 */
	private function document( ImportSession $session, $source_item_key, $title, $hash, array $metadata = array() ) {
		return new ImportPreparedDocument(
			$session->get_id(),
			$source_item_key,
			'markdown',
			$title,
			'<!-- wp:paragraph --><p>' . $title . '</p><!-- /wp:paragraph -->',
			1,
			$hash,
			array_merge( array( 'relative_path' => 'chapter.md' ), $metadata )
		);
	}

	/**
	 * Builds a resolved relationship mapping decision.
	 *
	 * @param string $decision_key Decision key.
	 * @param int    $post_id      Local post id.
	 * @param string $source_key   Source item key.
	 * @param int    $user_id      Local user id.
	 * @return ImportDecision
	 */
	private function resolved_relationship_decision( $decision_key, $post_id, $source_key, $user_id ) {
		return ImportDecision::pending(
			$decision_key,
			'Map the remote REST author and taxonomy relationships for imported draft post ' . (int) $post_id . '.',
			array(
				'post_id'         => (int) $post_id,
				'source_item_key' => (string) $source_key,
			)
		)->resolve(
			array(
				'author' => array(
					'local_user_id' => (int) $user_id,
				),
			)
		);
	}
}
