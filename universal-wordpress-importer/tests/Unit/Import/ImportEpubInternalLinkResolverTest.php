<?php
/**
 * Tests for EPUB internal link resolution.
 *
 * @package UniversalImporter\Tests\Unit\Import
 */

namespace UniversalImporter\Tests\Unit\Import;

use PHPUnit\Framework\TestCase;
use UniversalImporter\Import\ImportEpubInternalLinkResolver;
use UniversalImporter\Import\ImportIdempotencyRecord;
use UniversalImporter\Import\ImportPreparedDocument;
use UniversalImporter\Import\ImportSession;
use UniversalImporter\Import\WordPressImportSessionStore;

/**
 * Covers staged EPUB internal link resolution without WordPress loaded.
 */
final class ImportEpubInternalLinkResolverTest extends TestCase {
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
	 * Link resolution scans beyond the first prepared-document page.
	 *
	 * @return void
	 */
	public function test_resolver_finds_epub_internal_links_after_first_document_page() {
		$session = ImportSession::start_for_source( '/tmp/book.epub' );

		$this->store->save( $session );

		for ( $index = 1; $index <= 31; ++$index ) {
			$this->store->save_prepared_document( $this->epub_document( $session, $index, 30 === $index ) );
		}

		$target  = $this->store->find_prepared_document( $session->get_id(), 'local:book.epub:epub-spine:31' );
		$post_id = $this->posts->insert_or_update( $target );
		$this->store->remember_idempotency_record(
			$session->get_id(),
			new ImportIdempotencyRecord( 'post:' . $target->get_source_item_key(), 'post', (string) $post_id, $target->get_content_hash() )
		);

		$summary  = ( new ImportEpubInternalLinkResolver( $this->store, $this->posts ) )->advance( $session, 25 );
		$resolved = $this->store->find_prepared_document( $session->get_id(), 'local:book.epub:epub-spine:30' );

		$this->assertSame( 1, $summary['resolved'] );
		$this->assertSame( 'resolved', $resolved->get_metadata()['epub_internal_links_status'] );
		$this->assertCount( 0, $resolved->get_metadata()['epub_internal_links'] );
		$this->assertStringContainsString( 'href="https://local.example.test/imported/' . $post_id . '/#chapter-31"', $resolved->get_block_markup() );
	}

	/**
	 * Builds an EPUB prepared document fixture.
	 *
	 * @param ImportSession $session  Session.
	 * @param int           $index    Spine index.
	 * @param bool          $with_link Whether to stage an unresolved link.
	 * @return ImportPreparedDocument
	 */
	private function epub_document( ImportSession $session, $index, $with_link = false ) {
		$index            = (int) $index;
		$source_item_key  = 'local:book.epub:epub-spine:' . $index;
		$placeholder_href = '#universal-importer-epub-' . $index . '-to-31';
		$markup           = '<!-- wp:paragraph --><p>Chapter ' . $index . '</p><!-- /wp:paragraph -->';
		$metadata         = array(
			'epub_spine_index' => $index,
			'epub_entry_name'  => 'chapter-' . $index . '.xhtml',
		);

		if ( $with_link ) {
			$markup                          = '<!-- wp:paragraph --><p><a href="' . $placeholder_href . '">Next</a></p><!-- /wp:paragraph -->';
			$metadata['epub_internal_links'] = array(
				array(
					'original_href'           => 'chapter-31.xhtml#chapter-31',
					'rewritten_href'          => $placeholder_href,
					'epub_target_spine_index' => 31,
					'target_fragment'         => 'chapter-31',
				),
			);
		}

		return new ImportPreparedDocument(
			$session->get_id(),
			$source_item_key,
			'epub',
			'Chapter ' . $index,
			$markup,
			1,
			'hash-epub-' . $index,
			$metadata
		);
	}
}
