<?php
/**
 * Import session lock model.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

use InvalidArgumentException;

/**
 * Captures exclusive ownership of a resumable import tick.
 */
final class ImportSessionLock {
	/**
	 * Locked session id.
	 *
	 * @var ImportSessionId
	 */
	private $session_id;

	/**
	 * Owner identifier.
	 *
	 * @var string
	 */
	private $owner;

	/**
	 * Opaque lock token.
	 *
	 * @var string
	 */
	private $token;

	/**
	 * Expiration timestamp in UTC mysql format.
	 *
	 * @var string
	 */
	private $expires_at;

	/**
	 * Constructor.
	 *
	 * @param ImportSessionId $session_id Session id.
	 * @param string          $owner      Owner identifier.
	 * @param string          $token      Opaque lock token.
	 * @param string          $expires_at Expiration timestamp in UTC mysql format.
	 * @throws InvalidArgumentException When lock fields are invalid.
	 */
	public function __construct( ImportSessionId $session_id, $owner, $token, $expires_at ) {
		$owner      = trim( (string) $owner );
		$token      = (string) $token;
		$expires_at = (string) $expires_at;

		if ( '' === $owner ) {
			throw new InvalidArgumentException( 'Import session lock owner cannot be empty.' );
		}

		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			throw new InvalidArgumentException( 'Import session lock token must be 32 lowercase hexadecimal characters.' );
		}

		if ( '' === $expires_at ) {
			throw new InvalidArgumentException( 'Import session lock expiration cannot be empty.' );
		}

		$this->session_id = $session_id;
		$this->owner      = $owner;
		$this->token      = $token;
		$this->expires_at = $expires_at;
	}

	/**
	 * Returns the locked session id.
	 *
	 * @return ImportSessionId
	 */
	public function get_session_id() {
		return $this->session_id;
	}

	/**
	 * Returns the lock owner.
	 *
	 * @return string
	 */
	public function get_owner() {
		return $this->owner;
	}

	/**
	 * Returns the opaque lock token.
	 *
	 * @return string
	 */
	public function get_token() {
		return $this->token;
	}

	/**
	 * Returns the expiration timestamp in UTC mysql format.
	 *
	 * @return string
	 */
	public function get_expires_at() {
		return $this->expires_at;
	}

	/**
	 * Converts the lock to a storage-friendly array.
	 *
	 * @return array{session_id:string,owner:string,token:string,expires_at:string}
	 */
	public function to_array() {
		return array(
			'session_id' => $this->session_id->to_string(),
			'owner'      => $this->owner,
			'token'      => $this->token,
			'expires_at' => $this->expires_at,
		);
	}
}
