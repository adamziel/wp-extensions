<?php
/**
 * Import progress event model.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

use InvalidArgumentException;

/**
 * Carries an observable import event for admin, CLI, and logs.
 */
final class ImportProgressEvent {
	const LEVEL_INFO    = 'info';
	const LEVEL_WARNING = 'warning';
	const LEVEL_ERROR   = 'error';

	/**
	 * Allowed event levels.
	 *
	 * @var array<string,bool>
	 */
	private static $valid_levels = array(
		self::LEVEL_INFO    => true,
		self::LEVEL_WARNING => true,
		self::LEVEL_ERROR   => true,
	);

	/**
	 * Event level.
	 *
	 * @var string
	 */
	private $level;

	/**
	 * Machine-readable event type.
	 *
	 * @var string
	 */
	private $type;

	/**
	 * Human-readable message.
	 *
	 * @var string
	 */
	private $message;

	/**
	 * Structured diagnostic context.
	 *
	 * @var array<string,mixed>
	 */
	private $context;

	/**
	 * Creation timestamp in UTC mysql format, if persisted.
	 *
	 * @var string|null
	 */
	private $created_at;

	/**
	 * Constructor.
	 *
	 * @param string              $level      Event level.
	 * @param string              $type       Machine-readable event type.
	 * @param string              $message    Human-readable message.
	 * @param array<string,mixed> $context    Structured diagnostic context.
	 * @param string|null         $created_at Creation timestamp in UTC mysql format.
	 * @throws InvalidArgumentException When event fields are invalid.
	 */
	public function __construct( $level, $type, $message, array $context = array(), $created_at = null ) {
		$level      = (string) $level;
		$type       = trim( (string) $type );
		$message    = trim( (string) $message );
		$created_at = null === $created_at ? null : (string) $created_at;

		if ( ! isset( self::$valid_levels[ $level ] ) ) {
			throw new InvalidArgumentException( 'Invalid import progress event level.' );
		}

		if ( '' === $type ) {
			throw new InvalidArgumentException( 'Import progress event type cannot be empty.' );
		}

		if ( '' === $message ) {
			throw new InvalidArgumentException( 'Import progress event message cannot be empty.' );
		}

		$this->level      = $level;
		$this->type       = $type;
		$this->message    = $message;
		$this->context    = $context;
		$this->created_at = $created_at;
	}

	/**
	 * Recreates an event from storage.
	 *
	 * @param array<string,mixed> $data Stored event data.
	 * @return self
	 * @throws InvalidArgumentException When stored event data is invalid.
	 */
	public static function from_array( array $data ) {
		foreach ( array( 'level', 'type', 'message', 'context' ) as $required_key ) {
			if ( ! array_key_exists( $required_key, $data ) ) {
				throw new InvalidArgumentException( 'Import progress event data is missing a required key.' );
			}
		}

		if ( ! is_array( $data['context'] ) ) {
			throw new InvalidArgumentException( 'Import progress event context must be an array.' );
		}

		return new self(
			(string) $data['level'],
			(string) $data['type'],
			(string) $data['message'],
			$data['context'],
			isset( $data['created_at'] ) ? (string) $data['created_at'] : null
		);
	}

	/**
	 * Returns a copy with a creation timestamp.
	 *
	 * @param string $created_at Creation timestamp in UTC mysql format.
	 * @return self
	 */
	public function with_created_at( $created_at ) {
		return new self( $this->level, $this->type, $this->message, $this->context, $created_at );
	}

	/**
	 * Returns the event level.
	 *
	 * @return string
	 */
	public function get_level() {
		return $this->level;
	}

	/**
	 * Returns the machine-readable event type.
	 *
	 * @return string
	 */
	public function get_type() {
		return $this->type;
	}

	/**
	 * Returns the human-readable message.
	 *
	 * @return string
	 */
	public function get_message() {
		return $this->message;
	}

	/**
	 * Returns structured diagnostic context.
	 *
	 * @return array<string,mixed>
	 */
	public function get_context() {
		return $this->context;
	}

	/**
	 * Returns the creation timestamp, if known.
	 *
	 * @return string|null
	 */
	public function get_created_at() {
		return $this->created_at;
	}

	/**
	 * Converts the event to a storage-friendly array.
	 *
	 * @return array{level:string,type:string,message:string,context:array<string,mixed>,created_at:string|null}
	 */
	public function to_array() {
		return array(
			'level'      => $this->level,
			'type'       => $this->type,
			'message'    => $this->message,
			'context'    => $this->context,
			'created_at' => $this->created_at,
		);
	}
}
