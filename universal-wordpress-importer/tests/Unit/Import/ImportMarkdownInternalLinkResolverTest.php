<?php
/**
 * Tests for Markdown internal link resolution.
 *
 * @package UniversalImporter\Tests\Unit\Import
 */

namespace UniversalImporter\Tests\Unit\Import;

use PHPUnit\Framework\TestCase;
use UniversalImporter\Import\ImportIdempotencyRecord;
use UniversalImporter\Import\ImportMarkdownInternalLinkResolver;
use UniversalImporter\Import\ImportPreparedDocument;
use UniversalImporter\Import\ImportSession;
use UniversalImporter\Import\ImportSourceItem;
use UniversalImporter\Import\WordPressImportSessionStore;

/**
 * Covers staged Markdown document link resolution without WordPress loaded.
 */
final class ImportMarkdownInternalLinkResolverTest extends TestCase {
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
	 * GitHub Markdown links emitted from encoded Obsidian targets resolve.
	 *
	 * @return void
	 */
	public function test_resolver_decodes_github_markdown_link_paths_before_target_lookup() {
		$session     = ImportSession::start_for_source( 'https://github.com/Example/Docs' );
		$source_path = 'docs/Concepts.md';
		$target_path = 'docs/Guide [v2].md';
		$source_key  = $this->github_item_key( 'Example', 'Docs', 'main', $source_path );
		$target_key  = $this->github_item_key( 'Example', 'Docs', 'main', $target_path );

		$this->store->save( $session );
		$this->store->save_source_item( $this->github_source_item( $session, $source_key, $source_path ) );
		$this->store->save_source_item( $this->github_source_item( $session, $target_key, $target_path ) );

		$source = new ImportPreparedDocument(
			$session->get_id(),
			$source_key,
			'markdown',
			'Concepts',
			'<!-- wp:paragraph --><p>See <a href="Guide%20%5Bv2%5D.md#Part%20Two">Guide [v2]</a>.</p><!-- /wp:paragraph -->',
			1,
			'hash-source',
			array(
				'github_owner'      => 'Example',
				'github_repository' => 'Docs',
				'github_ref'        => 'main',
				'github_tree_path'  => $source_path,
			)
		);
		$target = new ImportPreparedDocument(
			$session->get_id(),
			$target_key,
			'markdown',
			'Guide [v2]',
			'<!-- wp:paragraph --><p>Guide body.</p><!-- /wp:paragraph -->',
			1,
			'hash-target',
			array(
				'github_owner'      => 'Example',
				'github_repository' => 'Docs',
				'github_ref'        => 'main',
				'github_tree_path'  => $target_path,
			)
		);

		$this->store->save_prepared_document( $source );
		$this->store->save_prepared_document( $target );

		$post_id = $this->posts->insert_or_update( $target );
		$this->store->remember_idempotency_record(
			$session->get_id(),
			new ImportIdempotencyRecord( 'post:' . $target_key, 'post', (string) $post_id, $target->get_content_hash() )
		);

		$summary  = ( new ImportMarkdownInternalLinkResolver( $this->store, $this->posts ) )->advance( $session, 10 );
		$resolved = $this->store->find_prepared_document( $session->get_id(), $source_key );

		$this->assertSame( 1, $summary['resolved'] );
		$this->assertSame( 'resolved', $resolved->get_metadata()['markdown_internal_links_status'] );
		$this->assertStringContainsString( 'href="' . $this->posts->get_permalink( $post_id ) . '#Part%20Two"', $resolved->get_block_markup() );
		$this->assertStringNotContainsString( 'Guide%20%5Bv2%5D.md', $resolved->get_block_markup() );
	}

	/**
	 * Creates a GitHub source item fixture.
	 *
	 * @param ImportSession $session Session.
	 * @param string        $key     Source item key.
	 * @param string        $path    Repository path.
	 * @return ImportSourceItem
	 */
	private function github_source_item( ImportSession $session, $key, $path ) {
		return ImportSourceItem::queued(
			$session->get_id(),
			$key,
			null,
			'https://api.github.test/repos/Example/Docs/contents/' . rawurlencode( basename( (string) $path ) ),
			(string) $path,
			ImportSourceItem::TYPE_FILE,
			array(
				'extension'       => pathinfo( (string) $path, PATHINFO_EXTENSION ),
				'github_owner'    => 'Example',
				'github_repo'     => 'Docs',
				'github_ref'      => 'main',
				'github_tree_path' => (string) $path,
			)
		)->with_status( ImportSourceItem::STATUS_IMPORTED );
	}

	/**
	 * Builds a GitHub source item key matching repository source discovery.
	 *
	 * @param string $owner Repository owner.
	 * @param string $repo  Repository name.
	 * @param string $ref   Repository ref.
	 * @param string $path  Repository path.
	 * @return string
	 */
	private function github_item_key( $owner, $repo, $ref, $path ) {
		return 'github-blob:' . hash(
			'sha256',
			strtolower( (string) $owner ) . '/' . strtolower( (string) $repo )
				. "\n" . (string) $ref
				. "\n" . (string) $path
		);
	}
}
