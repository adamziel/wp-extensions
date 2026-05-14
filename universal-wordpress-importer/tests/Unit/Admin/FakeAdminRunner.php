<?php
/**
 * Fake admin keepalive runner.
 *
 * @package UniversalImporter\Tests\Unit\Admin
 */

namespace UniversalImporter\Tests\Unit\Admin;

use UniversalImporter\Import\ImportProgressEvent;
use UniversalImporter\Import\ImportSession;
use UniversalImporter\Import\ImportSessionId;
use UniversalImporter\Import\WordPressImportSessionStore;

/**
 * Minimal runner used by admin page tests.
 */
final class FakeAdminRunner {
	/**
	 * Store under test.
	 *
	 * @var WordPressImportSessionStore
	 */
	private $store;

	/**
	 * Constructor.
	 *
	 * @param WordPressImportSessionStore $store Store.
	 */
	public function __construct( WordPressImportSessionStore $store ) {
		$this->store = $store;
	}

	/**
	 * Runs a fake continuation tick.
	 *
	 * @param ImportSessionId|null $session_id Optional session id.
	 * @param int                  $limit      Maximum sessions.
	 * @return array{processed:int,locked:int,skipped:int,errors:int}
	 */
	public function run( ImportSessionId $session_id = null, $limit = 10 ) {
		unset( $limit );

		if ( null === $session_id ) {
			return array(
				'processed' => 0,
				'locked'    => 0,
				'skipped'   => 0,
				'errors'    => 0,
			);
		}

		$session = $this->store->find( $session_id );

		if ( null === $session ) {
			return array(
				'processed' => 0,
				'locked'    => 0,
				'skipped'   => 1,
				'errors'    => 0,
			);
		}

		if ( ImportSession::STATUS_PENDING === $session->get_status() ) {
			$session = $session->mark_running();
			$this->store->save( $session );
		}

		$this->store->record_event(
			$session->get_id(),
			new ImportProgressEvent(
				ImportProgressEvent::LEVEL_INFO,
				'admin-runner.tick',
				'Fake admin runner continued the session.',
				array()
			)
		);

		return array(
			'processed' => 1,
			'locked'    => 0,
			'skipped'   => 0,
			'errors'    => 0,
		);
	}
}
