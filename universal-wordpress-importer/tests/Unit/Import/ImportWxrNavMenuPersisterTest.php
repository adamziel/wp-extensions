<?php
/**
 * Tests for WXR navigation menu persistence.
 *
 * @package UniversalImporter\Tests\Unit\Import
 */

namespace UniversalImporter\Tests\Unit\Import;

use PHPUnit\Framework\TestCase;
use UniversalImporter\Import\ImportSession;
use UniversalImporter\Import\ImportSourceItem;
use UniversalImporter\Import\ImportWxrNavMenuPersister;
use UniversalImporter\Import\WordPressImportSessionStore;

/**
 * Covers WXR navigation menu persistence without WordPress loaded.
 */
final class ImportWxrNavMenuPersisterTest extends TestCase {
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
	 * Navigation menu persistence scans beyond the first imported source-item page.
	 *
	 * @return void
	 */
	public function test_persister_finds_wxr_nav_menu_items_after_first_source_item_page() {
		$session = ImportSession::start_for_source( '/tmp/export.xml' );

		$this->store->save( $session );
		$this->posts->register_menu_location( 'primary', 'Primary Menu' );

		for ( $index = 1; $index <= 26; ++$index ) {
			$key      = 'local:export:' . str_pad( (string) $index, 3, '0', STR_PAD_LEFT );
			$metadata = 26 === $index ? $this->nav_menu_metadata() : array( 'wxr_source' => 'fixture' );

			$this->store->save_source_item(
				ImportSourceItem::queued(
					$session->get_id(),
					$key,
					null,
					'/tmp/export.xml',
					'export.xml',
					ImportSourceItem::TYPE_FILE,
					$metadata
				)->with_status( ImportSourceItem::STATUS_IMPORTED )
			);
		}

		$summary = ( new ImportWxrNavMenuPersister( $this->store, $this->posts, 'https://local.example.test/' ) )->advance( $session, 25 );
		$record  = $this->store->find_idempotency_record( $session->get_id(), 'nav-menu-item:local:export:026:50' );
		$menu    = $this->posts->get_menu_by_slug( 'primary' );

		$this->assertSame( 1, $summary['applied'] );
		$this->assertNotNull( $record );
		$this->assertNotNull( $menu );
		$this->assertCount( 1, $menu['items'] );
	}

	/**
	 * Builds WXR navigation menu item metadata.
	 *
	 * @return array<string,mixed>
	 */
	private function nav_menu_metadata() {
		return array(
			'wxr_nav_menu_items_by_id' => array(
				'50' => array(
					'id'         => 50,
					'title'      => 'Late Menu Item',
					'menu_order' => 1,
					'menu_slug'  => 'primary',
					'menu_name'  => 'Primary Menu',
					'meta'       => array(
						'_menu_item_type'             => 'custom',
						'_menu_item_url'              => 'https://example.org/late/',
						'_menu_item_menu_item_parent' => '0',
					),
				),
			),
		);
	}
}
