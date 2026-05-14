<?php
/**
 * Tests for the in-memory import session store.
 *
 * @package UniversalImporter\Tests\Unit\Import
 */

namespace UniversalImporter\Tests\Unit\Import;

use PHPUnit\Framework\TestCase;
use UniversalImporter\Import\ImportCheckpoint;
use UniversalImporter\Import\ImportSession;
use UniversalImporter\Import\InMemoryImportSessionStore;

/**
 * Covers session persistence contract behavior.
 */
final class InMemoryImportSessionStoreTest extends TestCase {
	/**
	 * Saved sessions are restored as snapshots, not shared mutable objects.
	 *
	 * @return void
	 */
	public function test_find_returns_saved_session_snapshot() {
		$store   = new InMemoryImportSessionStore();
		$session = ImportSession::start_for_source( 'github://example/repo', true )
			->mark_running()
			->with_checkpoint( new ImportCheckpoint( '/docs/index.md', 1 ) );

		$store->save( $session );
		$restored = $store->find( $session->get_id() );

		$this->assertNotNull( $restored );
		$this->assertSame( $session->to_array(), $restored->to_array() );
		$this->assertTrue( $restored->is_dry_run() );
		$this->assertNotSame( $session, $restored );
	}
}
