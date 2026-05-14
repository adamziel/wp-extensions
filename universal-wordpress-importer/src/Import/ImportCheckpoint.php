<?php
/**
 * Import checkpoint model.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

use InvalidArgumentException;

/**
 * Describes the exact tree cursor that can be resumed.
 */
final class ImportCheckpoint {
	/**
	 * Current traversal cursor.
	 *
	 * @var string
	 */
	private $cursor;

	/**
	 * Number of units fully processed before this checkpoint.
	 *
	 * @var int
	 */
	private $processed_count;

	/**
	 * Constructor.
	 *
	 * @param string $cursor          Current traversal cursor.
	 * @param int    $processed_count Number of processed units.
	 * @throws InvalidArgumentException When checkpoint fields are invalid.
	 */
	public function __construct( $cursor, $processed_count ) {
		$cursor          = (string) $cursor;
		$processed_count = (int) $processed_count;

		if ( '' === $cursor ) {
			throw new InvalidArgumentException( 'Checkpoint cursor cannot be empty.' );
		}

		if ( $processed_count < 0 ) {
			throw new InvalidArgumentException( 'Checkpoint processed count cannot be negative.' );
		}

		$this->cursor          = $cursor;
		$this->processed_count = $processed_count;
	}

	/**
	 * Returns the current traversal cursor.
	 *
	 * @return string
	 */
	public function get_cursor() {
		return $this->cursor;
	}

	/**
	 * Returns the number of fully processed units.
	 *
	 * @return int
	 */
	public function get_processed_count() {
		return $this->processed_count;
	}

	/**
	 * Converts the checkpoint to a storage-friendly array.
	 *
	 * @return array{cursor:string,processed_count:int}
	 */
	public function to_array() {
		return array(
			'cursor'          => $this->cursor,
			'processed_count' => $this->processed_count,
		);
	}

	/**
	 * Recreates a checkpoint from stored data.
	 *
	 * @param array<string,mixed> $data Stored checkpoint data.
	 * @return self
	 * @throws InvalidArgumentException When stored checkpoint data is invalid.
	 */
	public static function from_array( array $data ) {
		if ( ! isset( $data['cursor'], $data['processed_count'] ) ) {
			throw new InvalidArgumentException( 'Checkpoint data must include cursor and processed_count.' );
		}

		return new self( (string) $data['cursor'], (int) $data['processed_count'] );
	}
}
