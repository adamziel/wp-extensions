<?php
/**
 * In-memory import session store.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

/**
 * Test and development store for session snapshots.
 */
final class InMemoryImportSessionStore implements ImportSessionStoreInterface {
	/**
	 * Stored session snapshots keyed by session id.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private $sessions = array();

	/**
	 * Saves a session snapshot.
	 *
	 * @param ImportSession $session Session to save.
	 * @return void
	 */
	public function save( ImportSession $session ) {
		$this->sessions[ $session->get_id()->to_string() ] = $session->to_array();
	}

	/**
	 * Loads a session by id.
	 *
	 * @param ImportSessionId $id Session id.
	 * @return ImportSession|null
	 */
	public function find( ImportSessionId $id ) {
		$key = $id->to_string();

		if ( ! isset( $this->sessions[ $key ] ) ) {
			return null;
		}

		return ImportSession::from_array( $this->sessions[ $key ] );
	}
}
