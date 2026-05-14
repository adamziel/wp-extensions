<?php
/**
 * Import progress model.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

use InvalidArgumentException;

/**
 * Tracks user-facing import progress counters.
 */
final class ImportProgress {
	/**
	 * Total known units. Null means still discovering.
	 *
	 * @var int|null
	 */
	private $total;

	/**
	 * Completed units.
	 *
	 * @var int
	 */
	private $completed;

	/**
	 * Recoverable error count.
	 *
	 * @var int
	 */
	private $errors;

	/**
	 * Constructor.
	 *
	 * @param int|null $total     Total known units.
	 * @param int      $completed Completed units.
	 * @param int      $errors    Recoverable error count.
	 * @throws InvalidArgumentException When progress counters are invalid.
	 */
	public function __construct( $total, $completed, $errors ) {
		if ( null !== $total ) {
			$total = (int) $total;

			if ( $total < 0 ) {
				throw new InvalidArgumentException( 'Progress total cannot be negative.' );
			}
		}

		$completed = (int) $completed;
		$errors    = (int) $errors;

		if ( $completed < 0 ) {
			throw new InvalidArgumentException( 'Progress completed count cannot be negative.' );
		}

		if ( $errors < 0 ) {
			throw new InvalidArgumentException( 'Progress error count cannot be negative.' );
		}

		if ( null !== $total && $completed > $total ) {
			throw new InvalidArgumentException( 'Progress completed count cannot exceed total.' );
		}

		$this->total     = $total;
		$this->completed = $completed;
		$this->errors    = $errors;
	}

	/**
	 * Creates a starting progress snapshot.
	 *
	 * @return self
	 */
	public static function start() {
		return new self( null, 0, 0 );
	}

	/**
	 * Returns a copy with a known total.
	 *
	 * @param int $total Total known units.
	 * @return self
	 */
	public function with_total( $total ) {
		return new self( $total, $this->completed, $this->errors );
	}

	/**
	 * Returns a copy with one more completed unit.
	 *
	 * @return self
	 */
	public function complete_one() {
		return new self( $this->total, $this->completed + 1, $this->errors );
	}

	/**
	 * Returns a copy with one more recoverable error.
	 *
	 * @return self
	 */
	public function record_error() {
		return new self( $this->total, $this->completed, $this->errors + 1 );
	}

	/**
	 * Converts the progress snapshot to a storage-friendly array.
	 *
	 * @return array{total:int|null,completed:int,errors:int}
	 */
	public function to_array() {
		return array(
			'total'     => $this->total,
			'completed' => $this->completed,
			'errors'    => $this->errors,
		);
	}

	/**
	 * Recreates progress from stored data.
	 *
	 * @param array<string,mixed> $data Stored progress data.
	 * @return self
	 * @throws InvalidArgumentException When stored progress data is invalid.
	 */
	public static function from_array( array $data ) {
		if ( ! array_key_exists( 'total', $data ) || ! isset( $data['completed'], $data['errors'] ) ) {
			throw new InvalidArgumentException( 'Progress data must include total, completed, and errors.' );
		}

		$total = null === $data['total'] ? null : (int) $data['total'];

		return new self( $total, (int) $data['completed'], (int) $data['errors'] );
	}
}
