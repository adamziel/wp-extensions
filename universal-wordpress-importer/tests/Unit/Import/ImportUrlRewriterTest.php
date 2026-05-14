<?php
/**
 * Tests for confirmed first-party URL rewriting.
 *
 * @package UniversalImporter\Tests\Unit\Import
 */

namespace UniversalImporter\Tests\Unit\Import;

use PHPUnit\Framework\TestCase;
use UniversalImporter\Import\ImportDecision;
use UniversalImporter\Import\ImportPreparedDocument;
use UniversalImporter\Import\ImportSession;
use UniversalImporter\Import\ImportUrlInference;
use UniversalImporter\Import\ImportUrlRewriter;
use UniversalImporter\Import\WordPressImportSessionStore;

/**
 * Covers URL rewriting without a full WordPress runtime.
 */
final class ImportUrlRewriterTest extends TestCase {
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
	 * Sets up test dependencies.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->wpdb  = new FakeWpdb();
		$this->store = new WordPressImportSessionStore( $this->wpdb );
	}

	/**
	 * Confirmed first-party URLs are rewritten while outside domains are preserved.
	 *
	 * @return void
	 */
	public function test_rewriter_rewrites_only_confirmed_first_party_domains() {
		$session = ImportSession::start_for_source( '/tmp/source' );
		$this->store->save( $session );
		$this->store->save_decision(
			$session->get_id(),
			ImportDecision::pending(
				ImportUrlInference::DECISION_KEY,
				'Confirm first-party domains before URL rewriting.',
				array( 'domains' => array( 'source.example.test', 'cdn.source.example.test', 'outside.example.test' ) )
			)->resolve( array( 'confirmed_domains' => array( 'source.example.test', 'cdn.source.example.test' ) ) )
		);
		$this->store->save_prepared_document(
			$this->document(
				$session,
				'<!-- wp:freeform -->' . "\n"
				. '<a href="https://source.example.test/path/page?x=1#top">Internal</a>'
				. '<img src="https://cdn.source.example.test:8443/media/photo.jpg">'
				. '<a href="https://outside.example.test/keep">Outside</a>'
				. '<a href="https://source.example.test.evil/phish">Evil</a>'
				. "\n" . '<!-- /wp:freeform -->'
			)
		);

		$summary  = ( new ImportUrlRewriter( $this->store, 'https://local.example.test/site/' ) )->advance( $session );
		$document = $this->store->find_prepared_document( $session->get_id(), 'local:linked' );
		$events   = $this->store->list_events( $session->get_id(), 1 );

		$this->assertSame( 1, $summary['rewritten'] );
		$this->assertSame( 0, $summary['skipped'] );
		$this->assertNotNull( $document );
		$this->assertStringContainsString( 'https://local.example.test/site/path/page?x=1#top', $document->get_block_markup() );
		$this->assertStringContainsString( 'https://local.example.test/site/media/photo.jpg', $document->get_block_markup() );
		$this->assertStringContainsString( 'https://outside.example.test/keep', $document->get_block_markup() );
		$this->assertStringContainsString( 'https://source.example.test.evil/phish', $document->get_block_markup() );
		$this->assertSame( 'url.rewritten', $events[0]->get_type() );
		$this->assertSame( 2, $document->get_metadata()['url_rewriting']['rewritten_count'] );
	}

	/**
	 * Re-running the same rewrite target is idempotent.
	 *
	 * @return void
	 */
	public function test_rewriter_skips_documents_already_rewritten_for_same_target() {
		$session = ImportSession::start_for_source( '/tmp/source' );
		$this->store->save( $session );
		$this->store->save_decision(
			$session->get_id(),
			ImportDecision::pending(
				ImportUrlInference::DECISION_KEY,
				'Confirm first-party domains before URL rewriting.',
				array( 'domains' => array( 'source.example.test' ) )
			)->resolve( array( 'confirmed_domains' => array( 'source.example.test' ) ) )
		);
		$this->store->save_prepared_document( $this->document( $session, '<p>https://source.example.test/one</p>' ) );

		$rewriter = new ImportUrlRewriter( $this->store, 'https://local.example.test/' );
		$rewriter->advance( $session );
		$summary = $rewriter->advance( $session );

		$this->assertSame( 0, $summary['rewritten'] );
		$this->assertSame( 1, $summary['skipped'] );
		$this->assertStringContainsString(
			'https://local.example.test/one',
			$this->store->find_prepared_document( $session->get_id(), 'local:linked' )->get_block_markup()
		);
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
			'local:linked',
			'html',
			'Linked',
			$markup,
			1,
			'hash-a',
			array(
				'absolute_url_domains' => array( 'cdn.source.example.test', 'outside.example.test', 'source.example.test' ),
			)
		);
	}
}
