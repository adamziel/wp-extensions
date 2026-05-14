<?php
/**
 * Tests for import session state.
 *
 * @package UniversalImporter\Tests\Unit\Import
 */

namespace UniversalImporter\Tests\Unit\Import;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use UniversalImporter\Import\ImportCheckpoint;
use UniversalImporter\Import\ImportProgress;
use UniversalImporter\Import\ImportSession;

/**
 * Covers durable import session state behavior.
 */
final class ImportSessionTest extends TestCase {
	/**
	 * New sessions start pending with no checkpoint.
	 *
	 * @return void
	 */
	public function test_start_for_source_creates_pending_session() {
		$session = ImportSession::start_for_source( 'local://book.md' );

		$this->assertSame( 'local://book.md', $session->get_source() );
		$this->assertSame( ImportSession::STATUS_PENDING, $session->get_status() );
		$this->assertNull( $session->get_checkpoint() );
		$this->assertFalse( $session->is_dry_run() );
		$this->assertSame(
			array(
				'total'     => null,
				'completed' => 0,
				'errors'    => 0,
			),
			$session->get_progress()->to_array()
		);
	}

	/**
	 * Session state round trips through storage arrays.
	 *
	 * @return void
	 */
	public function test_session_round_trips_through_array_storage() {
		$session = ImportSession::start_for_source( 'zip://archive.zip#chapter-1.md', true )
			->mark_running()
			->with_progress( ( new ImportProgress( 10, 2, 0 ) )->record_error() )
			->with_checkpoint( new ImportCheckpoint( '/chapter-1.md:42', 2 ) );

		$restored = ImportSession::from_array( $session->to_array() );

		$this->assertSame( $session->to_array(), $restored->to_array() );
		$this->assertTrue( $restored->is_dry_run() );
	}

	/**
	 * Legacy stored sessions without dry-run state load as normal imports.
	 *
	 * @return void
	 */
	public function test_legacy_session_array_defaults_to_non_dry_run() {
		$data = ImportSession::start_for_source( 'local://legacy.md' )->to_array();
		unset( $data['dry_run'] );

		$restored = ImportSession::from_array( $data );

		$this->assertFalse( $restored->is_dry_run() );
		$this->assertFalse( $restored->to_array()['dry_run'] );
	}

	/**
	 * Stored zero-like dry-run values load as normal imports.
	 *
	 * @return void
	 */
	public function test_zero_string_dry_run_array_value_loads_as_non_dry_run() {
		$data            = ImportSession::start_for_source( 'local://zero.md' )->to_array();
		$data['dry_run'] = '0';

		$restored = ImportSession::from_array( $data );

		$this->assertFalse( $restored->is_dry_run() );
	}

	/**
	 * Invalid progress state is rejected.
	 *
	 * @return void
	 */
	public function test_progress_rejects_completed_count_above_total() {
		$this->expectException( InvalidArgumentException::class );

		new ImportProgress( 1, 2, 0 );
	}

	/**
	 * Empty sources cannot create sessions.
	 *
	 * @return void
	 */
	public function test_session_rejects_empty_source() {
		$this->expectException( InvalidArgumentException::class );

		ImportSession::start_for_source( '' );
	}
}
