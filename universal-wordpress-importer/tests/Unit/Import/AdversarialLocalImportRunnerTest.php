<?php
/**
 * Adversarial local import runner tests.
 *
 * @package UniversalImporter\Tests\Unit\Import
 */

namespace UniversalImporter\Tests\Unit\Import;

use PHPUnit\Framework\TestCase;
use UniversalImporter\Import\ImportRunner;
use UniversalImporter\Import\ImportRunnerControls;
use UniversalImporter\Import\ImportSession;
use UniversalImporter\Import\ImportSourceItem;
use UniversalImporter\Import\WordPressImportSessionStore;
use UniversalImporter\Tests\Fixtures\AdversarialLocalImportFixture;

/**
 * Covers end-to-end local fixture behavior across runner ticks.
 */
final class AdversarialLocalImportRunnerTest extends TestCase {
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
	 * Fixture trees to remove after tests.
	 *
	 * @var array<int,AdversarialLocalImportFixture>
	 */
	private $fixtures = array();

	/**
	 * Sets up runner dependencies.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->wpdb  = new FakeWpdb();
		$this->store = new WordPressImportSessionStore( $this->wpdb );
	}

	/**
	 * Cleans filesystem fixtures.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ( $this->fixtures as $fixture ) {
			$fixture->remove();
		}

		parent::tearDown();
	}

	/**
	 * Missing local sources become failed source items with durable diagnostics.
	 *
	 * @return void
	 */
	public function test_missing_local_source_records_failed_source_item() {
		$session = ImportSession::start_for_source( AdversarialLocalImportFixture::missing_path() );
		$this->store->save( $session );

		$summary  = ( new ImportRunner( $this->store, 'adversarial-test', 60 ) )->run( $session->get_id() );
		$restored = $this->store->find( $session->get_id() );
		$failed   = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_FAILED ), 1 );
		$events   = $this->store->list_events( $session->get_id(), 3 );

		$this->assertSame( 1, $summary['processed'] );
		$this->assertSame(
			array(
				'total'     => 1,
				'completed' => 1,
				'errors'    => 1,
			),
			$restored->get_progress()->to_array()
		);
		$this->assertCount( 1, $failed );
		$this->assertSame( 'Source path does not exist.', $failed[0]->get_metadata()['error'] );
		$this->assertSame( 'source.discovery_complete', $events[0]->get_type() );
	}

	/**
	 * Mixed local trees import supported files, skip unsupported files, and strip executable markup.
	 *
	 * @return void
	 */
	public function test_mixed_local_fixture_imports_supported_files_and_skips_unsupported_files() {
		$fixture          = $this->create_fixture();
		$session          = ImportSession::start_for_source( $fixture->root() );
		$posts            = new FakePostGateway();
		$expected_formats = array( 'html', 'markdown', 'text' );

		$this->store->save( $session );
		$this->run_until_queue_drained( $session, $posts );

		$restored        = $this->store->find( $session->get_id() );
		$documents       = $this->store->list_recent_prepared_documents( $session->get_id(), 10 );
		$skipped         = $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_SKIPPED ), 10 );
		$formats         = array();
		$titles          = array();
		$markup_by_title = array();

		foreach ( $documents as $document ) {
			$markup                                    = $document->get_block_markup();
			$formats[]                                 = $document->get_format();
			$titles[]                                  = $document->get_title();
			$markup_by_title[ $document->get_title() ] = $markup;
			$this->assertStringNotContainsString( '<script', $markup );
			$this->assertStringNotContainsString( 'javascript:', $markup );
			$this->assertStringNotContainsString( 'onclick=', $markup );
		}

		sort( $formats, SORT_STRING );

		$this->assertSame( 9, count( $documents ) );
		$this->assertArrayHasKey( 'Ambiguous', $markup_by_title );
		$this->assertStringContainsString( '<!-- wp:freeform -->', $markup_by_title['Ambiguous'] );
		$this->assertStringContainsString( '<div class="source-credit">Editorial credit that must stay with the imported figure.</div>', $markup_by_title['Ambiguous'] );
		$this->assertArrayHasKey( 'Malformed', $markup_by_title );
		$this->assertStringContainsString( 'Broken local HTML still imports.', $markup_by_title['Malformed'] );
		$this->assertContains( 'Large Notes part 1', $titles );
		$this->assertContains( 'large-notes part 2', $titles );
		$this->assertSame( $expected_formats, array_values( array_unique( $formats ) ) );
		$this->assertSame( 9, $posts->count_posts() );
		$this->assertGreaterThanOrEqual( 1, $this->count_skipped_unsupported_items( $skipped ) );
		$this->assertSame( 0, $this->store->count_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_QUEUED, ImportSourceItem::STATUS_PROCESSING ) ) );
		$this->assertSame( 0, $restored->get_progress()->to_array()['errors'] );
		$this->assertSame( 'source-discovery:complete', $restored->get_checkpoint()->get_cursor() );
	}

	/**
	 * Re-running completed local ticks does not duplicate WordPress draft pages.
	 *
	 * @return void
	 */
	public function test_duplicate_retries_do_not_create_duplicate_posts() {
		$fixture = $this->create_fixture();
		$session = ImportSession::start_for_source( $fixture->root() );
		$posts   = new FakePostGateway();

		$this->store->save( $session );
		$this->run_until_queue_drained( $session, $posts );
		$post_count_after_first_pass = $posts->count_posts();

		( new ImportRunner( $this->store, 'adversarial-test', 60, null, $posts ) )->run( $session->get_id() );
		( new ImportRunner( $this->store, 'adversarial-test', 60, null, $posts ) )->run( $session->get_id() );

		$this->assertSame( 9, $post_count_after_first_pass );
		$this->assertSame( $post_count_after_first_pass, $posts->count_posts() );
		$this->assertSame( 9, $this->store->count_idempotency_records_by_resource_type( $session->get_id(), 'post' ) );
	}

	/**
	 * Timeout-interrupted ticks leave the session resumable without discovering work prematurely.
	 *
	 * @return void
	 */
	public function test_interrupted_tick_resumes_local_fixture_without_losing_work() {
		$fixture = $this->create_fixture();
		$session = ImportSession::start_for_source( $fixture->root() );
		$posts   = new FakePostGateway();

		$this->store->save( $session );

		$interrupted_summary = ( new ImportRunner(
			$this->store,
			'adversarial-test',
			60,
			new ImportRunnerControls( false, true ),
			$posts
		) )->run( $session->get_id() );

		$this->assertSame( 1, $interrupted_summary['skipped'] );
		$this->assertSame( 0, $this->store->count_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_QUEUED, ImportSourceItem::STATUS_PROCESSING, ImportSourceItem::STATUS_IMPORTED ) ) );

		$this->run_until_queue_drained( $session, $posts );

		$this->assertSame( 9, $posts->count_posts() );
		$this->assertSame( 9, $this->store->count_prepared_documents( $session->get_id() ) );
		$this->assertSame( 0, $this->store->count_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_QUEUED, ImportSourceItem::STATUS_PROCESSING ) ) );
	}

	/**
	 * Creates a tracked fixture.
	 *
	 * @return AdversarialLocalImportFixture
	 */
	private function create_fixture() {
		$fixture          = AdversarialLocalImportFixture::create();
		$this->fixtures[] = $fixture;

		return $fixture;
	}

	/**
	 * Runs enough ticks for the small fixture tree to drain.
	 *
	 * @param ImportSession   $session Session.
	 * @param FakePostGateway $posts   Fake post gateway.
	 * @return void
	 */
	private function run_until_queue_drained( ImportSession $session, FakePostGateway $posts ) {
		for ( $tick = 0; $tick < 8; ++$tick ) {
			( new ImportRunner( $this->store, 'adversarial-test', 60, null, $posts ) )->run( $session->get_id() );

			if ( 0 === $this->store->count_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_QUEUED, ImportSourceItem::STATUS_PROCESSING ) ) ) {
				return;
			}
		}

		$this->fail( 'Local fixture source queue did not drain within the expected number of ticks.' );
	}

	/**
	 * Counts unsupported skipped file items.
	 *
	 * @param array<int,ImportSourceItem> $items Source items.
	 * @return int
	 */
	private function count_skipped_unsupported_items( array $items ) {
		$count = 0;

		foreach ( $items as $item ) {
			$metadata = $item->get_metadata();

			if ( isset( $metadata['processor_status'] ) && 'skipped' === $metadata['processor_status'] ) {
				++$count;
			}
		}

		return $count;
	}
}
