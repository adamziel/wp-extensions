<?php
/**
 * Import session store interface.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

/**
 * Persists import sessions between resumable ticks.
 */
interface ImportSessionStoreInterface {
	/**
	 * Saves a session snapshot.
	 *
	 * @param ImportSession $session Session to save.
	 * @return void
	 */
	public function save( ImportSession $session );

	/**
	 * Loads a session by id.
	 *
	 * @param ImportSessionId $id Session id.
	 * @return ImportSession|null
	 */
	public function find( ImportSessionId $id );
}
