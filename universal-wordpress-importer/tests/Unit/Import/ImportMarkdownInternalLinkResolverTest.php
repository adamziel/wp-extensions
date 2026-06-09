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
	 * GitHub docs root index documents are indexed as the / route.
	 *
	 * @return void
	 */
	public function test_resolver_indexes_root_index_route_for_absolute_root_links() {
		$session     = ImportSession::start_for_source( 'https://github.com/Example/Docs/tree/main/docs' );
		$source_path = 'docs/intro.md';
		$target_path = 'docs/index.md';
		$source_key  = $this->github_item_key( 'Example', 'Docs', 'main', $source_path );
		$target_key  = $this->github_item_key( 'Example', 'Docs', 'main', $target_path );

		$this->store->save( $session );
		$this->store->save_source_item( $this->github_source_item( $session, $source_key, $source_path ) );
		$this->store->save_source_item( $this->github_source_item( $session, $target_key, $target_path ) );

		$source = $this->github_prepared_document(
			$session,
			$source_key,
			$source_path,
			'Intro',
			'<!-- wp:paragraph --><p>Back to <a href="/">Docs home</a>.</p><!-- /wp:paragraph -->'
		);
		$target = $this->github_prepared_document(
			$session,
			$target_key,
			$target_path,
			'Docs Home',
			'<!-- wp:paragraph --><p>Home body.</p><!-- /wp:paragraph -->'
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
		$this->assertStringContainsString( 'href="' . $this->posts->get_permalink( $post_id ) . '"', $resolved->get_block_markup() );
		$this->assertStringNotContainsString( 'href="/"', $resolved->get_block_markup() );
	}

	/**
	 * Relative route links from root index docs resolve without front matter.
	 *
	 * @return void
	 */
	public function test_resolver_rewrites_relative_route_links_from_root_index_without_front_matter() {
		$session     = ImportSession::start_for_source( 'https://github.com/Example/Docs/tree/main/docs' );
		$source_path = 'docs/index.md';
		$target_path = 'docs/getting-started.md';
		$source_key  = $this->github_item_key( 'Example', 'Docs', 'main', $source_path );
		$target_key  = $this->github_item_key( 'Example', 'Docs', 'main', $target_path );

		$this->store->save( $session );
		$this->store->save_source_item( $this->github_source_item( $session, $source_key, $source_path ) );
		$this->store->save_source_item( $this->github_source_item( $session, $target_key, $target_path ) );

		$source = $this->github_prepared_document(
			$session,
			$source_key,
			$source_path,
			'Docs Home',
			'<!-- wp:paragraph --><p>Read <a href="./getting-started#install">Getting Started</a>.</p><!-- /wp:paragraph -->'
		);
		$target = $this->github_prepared_document(
			$session,
			$target_key,
			$target_path,
			'Getting Started',
			'<!-- wp:paragraph --><p>Getting started body.</p><!-- /wp:paragraph -->'
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
		$this->assertStringContainsString( 'href="' . $this->posts->get_permalink( $post_id ) . '#install"', $resolved->get_block_markup() );
		$this->assertStringNotContainsString( './getting-started#install', $resolved->get_block_markup() );
	}

	/**
	 * GitHub docs route links resolve through Docusaurus front matter route metadata.
	 *
	 * @return void
	 */
	public function test_resolver_rewrites_github_docusaurus_root_route_links() {
		$session     = ImportSession::start_for_source( 'https://github.com/WordPress/wordpress-playground/tree/trunk/packages/docs/site/docs/blueprints/tutorial' );
		$source_path = 'packages/docs/site/docs/blueprints/tutorial/index.md';
		$target_path = 'packages/docs/site/docs/blueprints/tutorial/build-your-first-blueprint.md';
		$source_key  = $this->github_item_key( 'WordPress', 'wordpress-playground', 'trunk', $source_path );
		$target_key  = $this->github_item_key( 'WordPress', 'wordpress-playground', 'trunk', $target_path );

		$this->store->save( $session );
		$this->store->save_source_item( $this->github_source_item( $session, $source_key, $source_path ) );
		$this->store->save_source_item( $this->github_source_item( $session, $target_key, $target_path ) );

		$source = $this->github_prepared_document(
			$session,
			$source_key,
			$source_path,
			'Blueprints 101',
			'<!-- wp:paragraph --><p>Start with <a href="/blueprints/tutorial/build-your-first-blueprint">your first Blueprint</a> or <a href="https://developer.wordpress.org/blueprints/tutorial/build-your-first-blueprint">external docs</a>.</p><!-- /wp:paragraph -->',
			array(
				'markdown_front_matter_slug' => '/blueprints/tutorial',
				'markdown_route_path'        => '/blueprints/tutorial',
			)
		);
		$target = $this->github_prepared_document(
			$session,
			$target_key,
			$target_path,
			'Build your first Blueprint',
			'<!-- wp:paragraph --><p>Blueprint body.</p><!-- /wp:paragraph -->',
			array(
				'markdown_front_matter_slug' => '/blueprints/tutorial/build-your-first-blueprint',
				'markdown_route_path'        => '/blueprints/tutorial/build-your-first-blueprint',
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
		$this->assertStringContainsString( 'href="' . $this->posts->get_permalink( $post_id ) . '"', $resolved->get_block_markup() );
		$this->assertStringNotContainsString( 'href="/blueprints/tutorial/build-your-first-blueprint"', $resolved->get_block_markup() );
		$this->assertStringContainsString( 'href="https://developer.wordpress.org/blueprints/tutorial/build-your-first-blueprint"', $resolved->get_block_markup() );
	}

	/**
	 * Relative extensionless route links resolve from routed index docs and preserve fragments.
	 *
	 * @return void
	 */
	public function test_resolver_rewrites_relative_route_links_with_fragments() {
		$session     = ImportSession::start_for_source( 'https://github.com/WordPress/wordpress-playground/tree/trunk/packages/docs/site/docs/blueprints/tutorial' );
		$source_path = 'packages/docs/site/docs/blueprints/tutorial/index.md';
		$target_path = 'packages/docs/site/docs/blueprints/tutorial/build-your-first-blueprint.md';
		$source_key  = $this->github_item_key( 'WordPress', 'wordpress-playground', 'trunk', $source_path );
		$target_key  = $this->github_item_key( 'WordPress', 'wordpress-playground', 'trunk', $target_path );

		$this->store->save( $session );
		$this->store->save_source_item( $this->github_source_item( $session, $source_key, $source_path ) );
		$this->store->save_source_item( $this->github_source_item( $session, $target_key, $target_path ) );

		$source = $this->github_prepared_document(
			$session,
			$source_key,
			$source_path,
			'Blueprints 101',
			'<!-- wp:paragraph --><p>Next: <a href="./build-your-first-blueprint#step-two">step two</a>.</p><!-- /wp:paragraph -->',
			array( 'markdown_route_path' => '/blueprints/tutorial' )
		);
		$target = $this->github_prepared_document(
			$session,
			$target_key,
			$target_path,
			'Build your first Blueprint',
			'<!-- wp:paragraph --><p>Blueprint body.</p><!-- /wp:paragraph -->',
			array(
				'markdown_front_matter_permalink' => '/blueprints/tutorial/build-your-first-blueprint',
				'markdown_route_path'             => '/blueprints/tutorial/build-your-first-blueprint',
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
		$this->assertStringContainsString( 'href="' . $this->posts->get_permalink( $post_id ) . '#step-two"', $resolved->get_block_markup() );
		$this->assertStringNotContainsString( './build-your-first-blueprint#step-two', $resolved->get_block_markup() );
	}

	/**
	 * Relative slugs use the canonical routed path as the relative link base.
	 *
	 * @return void
	 */
	public function test_resolver_uses_canonical_route_path_as_relative_slug_base() {
		$session     = ImportSession::start_for_source( 'https://github.com/Example/Docs/tree/main/docs/guide' );
		$source_path = 'docs/guide/current.md';
		$target_path = 'docs/guide/next.md';
		$decoy_path  = 'docs/next.md';
		$source_key  = $this->github_item_key( 'Example', 'Docs', 'main', $source_path );
		$target_key  = $this->github_item_key( 'Example', 'Docs', 'main', $target_path );
		$decoy_key   = $this->github_item_key( 'Example', 'Docs', 'main', $decoy_path );

		$this->store->save( $session );
		$this->store->save_source_item( $this->github_source_item( $session, $source_key, $source_path ) );
		$this->store->save_source_item( $this->github_source_item( $session, $target_key, $target_path ) );
		$this->store->save_source_item( $this->github_source_item( $session, $decoy_key, $decoy_path ) );

		$source = $this->github_prepared_document(
			$session,
			$source_key,
			$source_path,
			'Current Guide',
			'<!-- wp:paragraph --><p>Continue to <a href="./next">Next</a>.</p><!-- /wp:paragraph -->',
			array(
				'markdown_front_matter_slug' => 'custom-current',
				'markdown_route_path'        => '/guide/custom-current',
			)
		);
		$target = $this->github_prepared_document(
			$session,
			$target_key,
			$target_path,
			'Next Guide',
			'<!-- wp:paragraph --><p>Next guide body.</p><!-- /wp:paragraph -->',
			array( 'markdown_route_path' => '/guide/next' )
		);
		$decoy = $this->github_prepared_document(
			$session,
			$decoy_key,
			$decoy_path,
			'Root Next',
			'<!-- wp:paragraph --><p>Root next body.</p><!-- /wp:paragraph -->',
			array( 'markdown_route_path' => '/next' )
		);

		$this->store->save_prepared_document( $source );
		$this->store->save_prepared_document( $target );
		$this->store->save_prepared_document( $decoy );

		$target_post_id = $this->posts->insert_or_update( $target );
		$decoy_post_id  = $this->posts->insert_or_update( $decoy );
		$this->store->remember_idempotency_record(
			$session->get_id(),
			new ImportIdempotencyRecord( 'post:' . $target_key, 'post', (string) $target_post_id, $target->get_content_hash() )
		);
		$this->store->remember_idempotency_record(
			$session->get_id(),
			new ImportIdempotencyRecord( 'post:' . $decoy_key, 'post', (string) $decoy_post_id, $decoy->get_content_hash() )
		);

		$summary  = ( new ImportMarkdownInternalLinkResolver( $this->store, $this->posts ) )->advance( $session, 10 );
		$resolved = $this->store->find_prepared_document( $session->get_id(), $source_key );

		$this->assertSame( 1, $summary['resolved'] );
		$this->assertStringContainsString( 'href="' . $this->posts->get_permalink( $target_post_id ) . '"', $resolved->get_block_markup() );
		$this->assertStringNotContainsString( 'href="' . $this->posts->get_permalink( $decoy_post_id ) . '"', $resolved->get_block_markup() );
	}

	/**
	 * Percent-encoded route segments resolve to decoded prepared document routes.
	 *
	 * @return void
	 */
	public function test_resolver_decodes_percent_encoded_route_segments() {
		$session     = ImportSession::start_for_source( 'https://github.com/Example/Docs/tree/main/docs/blueprints/tutorial' );
		$source_path = 'docs/blueprints/tutorial/index.md';
		$target_path = 'docs/blueprints/tutorial/Guide [v2].md';
		$source_key  = $this->github_item_key( 'Example', 'Docs', 'main', $source_path );
		$target_key  = $this->github_item_key( 'Example', 'Docs', 'main', $target_path );

		$this->store->save( $session );
		$this->store->save_source_item( $this->github_source_item( $session, $source_key, $source_path ) );
		$this->store->save_source_item( $this->github_source_item( $session, $target_key, $target_path ) );

		$source = $this->github_prepared_document(
			$session,
			$source_key,
			$source_path,
			'Blueprints 101',
			'<!-- wp:paragraph --><p>See <a href="/blueprints/tutorial/Guide%20%5Bv2%5D">Guide [v2]</a>.</p><!-- /wp:paragraph -->',
			array( 'markdown_route_path' => '/blueprints/tutorial' )
		);
		$target = $this->github_prepared_document(
			$session,
			$target_key,
			$target_path,
			'Guide [v2]',
			'<!-- wp:paragraph --><p>Guide body.</p><!-- /wp:paragraph -->',
			array( 'markdown_route_path' => '/blueprints/tutorial/Guide [v2]' )
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
		$this->assertStringContainsString( 'href="' . $this->posts->get_permalink( $post_id ) . '"', $resolved->get_block_markup() );
		$this->assertStringNotContainsString( 'Guide%20%5Bv2%5D', $resolved->get_block_markup() );
	}

	/**
	 * Unmatched route links are left unchanged but recorded in prepared metadata.
	 *
	 * @return void
	 */
	public function test_resolver_records_unresolved_route_link_diagnostics() {
		$session     = ImportSession::start_for_source( 'https://github.com/Example/Docs/tree/main/docs/blueprints/tutorial' );
		$source_path = 'docs/blueprints/tutorial/index.md';
		$source_key  = $this->github_item_key( 'Example', 'Docs', 'main', $source_path );

		$this->store->save( $session );
		$this->store->save_source_item( $this->github_source_item( $session, $source_key, $source_path ) );

		$source = $this->github_prepared_document(
			$session,
			$source_key,
			$source_path,
			'Blueprints 101',
			'<!-- wp:paragraph --><p>See <a href="/blueprints/tutorial/not-imported">not imported</a>.</p><!-- /wp:paragraph -->',
			array( 'markdown_route_path' => '/blueprints/tutorial' )
		);

		$this->store->save_prepared_document( $source );

		$summary  = ( new ImportMarkdownInternalLinkResolver( $this->store, $this->posts ) )->advance( $session, 10 );
		$resolved = $this->store->find_prepared_document( $session->get_id(), $source_key );
		$metadata = $resolved->get_metadata();

		$this->assertSame( 1, $summary['skipped'] );
		$this->assertSame( 'unresolved-routes', $metadata['markdown_internal_links_status'] );
		$this->assertCount( 1, $metadata['markdown_internal_route_links_unresolved'] );
		$this->assertSame( '/blueprints/tutorial/not-imported', $metadata['markdown_internal_route_links_unresolved'][0]['href'] );
		$this->assertStringContainsString( 'href="/blueprints/tutorial/not-imported"', $resolved->get_block_markup() );
		$this->assertContains( 'markdown.internal_route_links_unresolved', $this->event_types( $session ) );
	}

	/**
	 * Mixed resolved and unresolved route links still emit the dedicated warning.
	 *
	 * @return void
	 */
	public function test_resolver_warns_for_mixed_resolved_and_unresolved_route_links() {
		$session     = ImportSession::start_for_source( 'https://github.com/Example/Docs/tree/main/docs/blueprints/tutorial' );
		$source_path = 'docs/blueprints/tutorial/index.md';
		$target_path = 'docs/blueprints/tutorial/build-your-first-blueprint.md';
		$source_key  = $this->github_item_key( 'Example', 'Docs', 'main', $source_path );
		$target_key  = $this->github_item_key( 'Example', 'Docs', 'main', $target_path );

		$this->store->save( $session );
		$this->store->save_source_item( $this->github_source_item( $session, $source_key, $source_path ) );
		$this->store->save_source_item( $this->github_source_item( $session, $target_key, $target_path ) );

		$source = $this->github_prepared_document(
			$session,
			$source_key,
			$source_path,
			'Blueprints 101',
			'<!-- wp:paragraph --><p>Start with <a href="/blueprints/tutorial/build-your-first-blueprint">your first Blueprint</a> or visit <a href="/blueprints/tutorial/not-imported">missing route</a>.</p><!-- /wp:paragraph -->',
			array( 'markdown_route_path' => '/blueprints/tutorial' )
		);
		$target = $this->github_prepared_document(
			$session,
			$target_key,
			$target_path,
			'Build your first Blueprint',
			'<!-- wp:paragraph --><p>Blueprint body.</p><!-- /wp:paragraph -->',
			array( 'markdown_route_path' => '/blueprints/tutorial/build-your-first-blueprint' )
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
		$metadata = $resolved->get_metadata();

		$this->assertSame( 1, $summary['resolved'] );
		$this->assertSame( 'partial', $metadata['markdown_internal_links_status'] );
		$this->assertCount( 1, $metadata['markdown_internal_route_links_unresolved'] );
		$this->assertSame( '/blueprints/tutorial/not-imported', $metadata['markdown_internal_route_links_unresolved'][0]['href'] );
		$this->assertStringContainsString( 'href="' . $this->posts->get_permalink( $post_id ) . '"', $resolved->get_block_markup() );
		$this->assertStringContainsString( 'href="/blueprints/tutorial/not-imported"', $resolved->get_block_markup() );
		$this->assertContains( 'markdown.internal_route_links_unresolved', $this->event_types( $session ) );
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
	 * Creates a prepared GitHub Markdown document fixture.
	 *
	 * @param ImportSession        $session Session.
	 * @param string               $key     Source item key.
	 * @param string               $path    Repository path.
	 * @param string               $title   Document title.
	 * @param string               $markup  Prepared block markup.
	 * @param array<string,mixed>  $metadata Extra metadata.
	 * @return ImportPreparedDocument
	 */
	private function github_prepared_document( ImportSession $session, $key, $path, $title, $markup, array $metadata = array() ) {
		$is_playground = false !== strpos( (string) $path, 'packages/docs/site/docs' );

		return new ImportPreparedDocument(
			$session->get_id(),
			$key,
			'markdown',
			$title,
			$markup,
			1,
			'hash-' . substr( hash( 'sha256', (string) $key . "\n" . (string) $markup ), 0, 16 ),
			array_merge(
				array(
					'github_owner'      => $is_playground ? 'WordPress' : 'Example',
					'github_repository' => $is_playground ? 'wordpress-playground' : 'Docs',
					'github_ref'        => $is_playground ? 'trunk' : 'main',
					'github_tree_path'  => (string) $path,
				),
				$metadata
			)
		);
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

	/**
	 * Returns recent event types for a session.
	 *
	 * @param ImportSession $session Session.
	 * @return array<int,string>
	 */
	private function event_types( ImportSession $session ) {
		return array_map(
			function ( $event ) {
				return $event->get_type();
			},
			$this->store->list_events( $session->get_id(), 10 )
		);
	}
}
