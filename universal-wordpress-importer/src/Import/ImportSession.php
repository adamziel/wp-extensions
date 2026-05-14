<?php
/**
 * Import session aggregate.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

use InvalidArgumentException;

/**
 * Captures durable state needed to resume an import session.
 */
final class ImportSession {
	const STATUS_PENDING = 'pending';
	const STATUS_RUNNING = 'running';
	const STATUS_PAUSED  = 'paused';
	const STATUS_FAILED  = 'failed';
	const STATUS_DONE    = 'done';
	const STATUS_ABORTED = 'aborted';

	/**
	 * Allowed session statuses.
	 *
	 * @var array<string,bool>
	 */
	private static $valid_statuses = array(
		self::STATUS_PENDING => true,
		self::STATUS_RUNNING => true,
		self::STATUS_PAUSED  => true,
		self::STATUS_FAILED  => true,
		self::STATUS_DONE    => true,
		self::STATUS_ABORTED => true,
	);

	/**
	 * Session id.
	 *
	 * @var ImportSessionId
	 */
	private $id;

	/**
	 * Source descriptor.
	 *
	 * @var string
	 */
	private $source;

	/**
	 * Session status.
	 *
	 * @var string
	 */
	private $status;

	/**
	 * Progress snapshot.
	 *
	 * @var ImportProgress
	 */
	private $progress;

	/**
	 * Last durable checkpoint.
	 *
	 * @var ImportCheckpoint|null
	 */
	private $checkpoint;

	/**
	 * Whether this session should run without mutating imported content.
	 *
	 * @var bool
	 */
	private $dry_run;

	/**
	 * Constructor.
	 *
	 * @param ImportSessionId       $id         Session id.
	 * @param string                $source     Source descriptor.
	 * @param string                $status     Session status.
	 * @param ImportProgress        $progress   Progress snapshot.
	 * @param ImportCheckpoint|null $checkpoint Last checkpoint.
	 * @param bool                  $dry_run    Whether this is a dry-run session.
	 * @throws InvalidArgumentException When session fields are invalid.
	 */
	private function __construct( ImportSessionId $id, $source, $status, ImportProgress $progress, ImportCheckpoint $checkpoint = null, $dry_run = false ) {
		$source = (string) $source;
		$status = (string) $status;

		if ( '' === $source ) {
			throw new InvalidArgumentException( 'Import session source cannot be empty.' );
		}

		if ( ! isset( self::$valid_statuses[ $status ] ) ) {
			throw new InvalidArgumentException( 'Invalid import session status.' );
		}

		$this->id         = $id;
		$this->source     = $source;
		$this->status     = $status;
		$this->progress   = $progress;
		$this->checkpoint = $checkpoint;
		$this->dry_run    = (bool) $dry_run;
	}

	/**
	 * Starts a new pending import session.
	 *
	 * @param string $source  Source descriptor.
	 * @param bool   $dry_run Whether this is a dry-run session.
	 * @return self
	 * @throws InvalidArgumentException When the source is empty.
	 */
	public static function start_for_source( $source, $dry_run = false ) {
		return new self( ImportSessionId::generate(), $source, self::STATUS_PENDING, ImportProgress::start(), null, $dry_run );
	}

	/**
	 * Starts a new pending import session with a caller-provided id.
	 *
	 * @param ImportSessionId $id      Session id.
	 * @param string          $source  Source descriptor.
	 * @param bool            $dry_run Whether this is a dry-run session.
	 * @return self
	 * @throws InvalidArgumentException When the source is empty.
	 */
	public static function start_with_id_for_source( ImportSessionId $id, $source, $dry_run = false ) {
		return new self( $id, $source, self::STATUS_PENDING, ImportProgress::start(), null, $dry_run );
	}

	/**
	 * Recreates a session from storage.
	 *
	 * @param array<string,mixed> $data Stored session data.
	 * @return self
	 * @throws InvalidArgumentException When stored session data is invalid.
	 */
	public static function from_array( array $data ) {
		foreach ( array( 'id', 'source', 'status', 'progress' ) as $required_key ) {
			if ( ! array_key_exists( $required_key, $data ) ) {
				throw new InvalidArgumentException( 'Import session data is missing a required key.' );
			}
		}

		$checkpoint = null;

		if ( isset( $data['checkpoint'] ) && is_array( $data['checkpoint'] ) ) {
			$checkpoint = ImportCheckpoint::from_array( $data['checkpoint'] );
		}

		if ( ! is_array( $data['progress'] ) ) {
			throw new InvalidArgumentException( 'Import session progress data must be an array.' );
		}

		return new self(
			ImportSessionId::from_string( (string) $data['id'] ),
			(string) $data['source'],
			(string) $data['status'],
			ImportProgress::from_array( $data['progress'] ),
			$checkpoint,
			array_key_exists( 'dry_run', $data ) ? ! empty( $data['dry_run'] ) : false
		);
	}

	/**
	 * Returns a copy with running status.
	 *
	 * @return self
	 */
	public function mark_running() {
		return $this->with_status( self::STATUS_RUNNING );
	}

	/**
	 * Returns a copy with paused status.
	 *
	 * @return self
	 */
	public function mark_paused() {
		return $this->with_status( self::STATUS_PAUSED );
	}

	/**
	 * Returns a copy with failed status.
	 *
	 * @return self
	 */
	public function mark_failed() {
		return $this->with_status( self::STATUS_FAILED );
	}

	/**
	 * Returns a copy with done status.
	 *
	 * @return self
	 */
	public function mark_done() {
		return $this->with_status( self::STATUS_DONE );
	}

	/**
	 * Returns a copy with aborted status.
	 *
	 * @return self
	 */
	public function mark_aborted() {
		return $this->with_status( self::STATUS_ABORTED );
	}

	/**
	 * Returns a copy with updated progress.
	 *
	 * @param ImportProgress $progress Progress snapshot.
	 * @return self
	 */
	public function with_progress( ImportProgress $progress ) {
		return new self( $this->id, $this->source, $this->status, $progress, $this->checkpoint, $this->dry_run );
	}

	/**
	 * Returns a copy with a new checkpoint.
	 *
	 * @param ImportCheckpoint $checkpoint Checkpoint.
	 * @return self
	 */
	public function with_checkpoint( ImportCheckpoint $checkpoint ) {
		return new self( $this->id, $this->source, $this->status, $this->progress, $checkpoint, $this->dry_run );
	}

	/**
	 * Returns the session id.
	 *
	 * @return ImportSessionId
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * Returns the source descriptor.
	 *
	 * @return string
	 */
	public function get_source() {
		return $this->source;
	}

	/**
	 * Returns the current status.
	 *
	 * @return string
	 */
	public function get_status() {
		return $this->status;
	}

	/**
	 * Returns the progress snapshot.
	 *
	 * @return ImportProgress
	 */
	public function get_progress() {
		return $this->progress;
	}

	/**
	 * Returns the last checkpoint, if one exists.
	 *
	 * @return ImportCheckpoint|null
	 */
	public function get_checkpoint() {
		return $this->checkpoint;
	}

	/**
	 * Returns whether this session is a dry run.
	 *
	 * @return bool
	 */
	public function is_dry_run() {
		return $this->dry_run;
	}

	/**
	 * Converts the session to a storage-friendly array.
	 *
	 * @return array{id:string,source:string,status:string,progress:array<string,mixed>,checkpoint:array<string,mixed>|null,dry_run:bool}
	 */
	public function to_array() {
		return array(
			'id'         => $this->id->to_string(),
			'source'     => $this->source,
			'status'     => $this->status,
			'progress'   => $this->progress->to_array(),
			'checkpoint' => null === $this->checkpoint ? null : $this->checkpoint->to_array(),
			'dry_run'    => $this->dry_run,
		);
	}

	/**
	 * Returns a copy with a new status.
	 *
	 * @param string $status New status.
	 * @return self
	 */
	private function with_status( $status ) {
		return new self( $this->id, $this->source, $status, $this->progress, $this->checkpoint, $this->dry_run );
	}
}
