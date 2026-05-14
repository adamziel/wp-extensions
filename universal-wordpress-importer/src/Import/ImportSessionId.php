<?php
/**
 * Import session identifier.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

use InvalidArgumentException;

/**
 * Validates and carries an import session id.
 */
final class ImportSessionId {
	/**
	 * Session id value.
	 *
	 * @var string
	 */
	private $value;

	/**
	 * Creates a new random session id.
	 *
	 * @return self
	 */
	public static function generate() {
		return new self( 'import_' . bin2hex( random_bytes( 16 ) ) );
	}

	/**
	 * Recreates a session id from storage or user input.
	 *
	 * @param string $value Session id value.
	 * @return self
	 */
	public static function from_string( $value ) {
		return new self( $value );
	}

	/**
	 * Constructor.
	 *
	 * @param string $value Session id value.
	 * @throws InvalidArgumentException When the session id format is invalid.
	 */
	private function __construct( $value ) {
		$value = (string) $value;

		if ( 1 !== preg_match( '/^import_[a-f0-9]{32}$/', $value ) ) {
			throw new InvalidArgumentException( 'Import session ids must use the import_ prefix followed by 32 lowercase hexadecimal characters.' );
		}

		$this->value = $value;
	}

	/**
	 * Returns the session id string.
	 *
	 * @return string
	 */
	public function to_string() {
		return $this->value;
	}
}
