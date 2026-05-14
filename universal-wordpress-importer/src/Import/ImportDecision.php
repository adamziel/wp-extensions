<?php
/**
 * Import user decision model.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

use InvalidArgumentException;

/**
 * Represents a user decision required before an import can continue.
 */
final class ImportDecision {
	const STATUS_PENDING  = 'pending';
	const STATUS_RESOLVED = 'resolved';

	/**
	 * Allowed decision statuses.
	 *
	 * @var array<string,bool>
	 */
	private static $valid_statuses = array(
		self::STATUS_PENDING  => true,
		self::STATUS_RESOLVED => true,
	);

	/**
	 * Decision key unique within a session.
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Prompt shown to the user.
	 *
	 * @var string
	 */
	private $prompt;

	/**
	 * Structured options for the user.
	 *
	 * @var array<string,mixed>
	 */
	private $options;

	/**
	 * Decision status.
	 *
	 * @var string
	 */
	private $status;

	/**
	 * Structured answer, once resolved.
	 *
	 * @var array<string,mixed>|null
	 */
	private $answer;

	/**
	 * Constructor.
	 *
	 * @param string                   $key     Decision key unique within a session.
	 * @param string                   $prompt  Prompt shown to the user.
	 * @param array<string,mixed>      $options Structured options for the user.
	 * @param string                   $status  Decision status.
	 * @param array<string,mixed>|null $answer Structured answer, once resolved.
	 * @throws InvalidArgumentException When decision fields are invalid.
	 */
	private function __construct( $key, $prompt, array $options, $status, array $answer = null ) {
		$key    = trim( (string) $key );
		$prompt = trim( (string) $prompt );
		$status = (string) $status;

		if ( '' === $key ) {
			throw new InvalidArgumentException( 'Import decision key cannot be empty.' );
		}

		if ( '' === $prompt ) {
			throw new InvalidArgumentException( 'Import decision prompt cannot be empty.' );
		}

		if ( ! isset( self::$valid_statuses[ $status ] ) ) {
			throw new InvalidArgumentException( 'Invalid import decision status.' );
		}

		if ( self::STATUS_RESOLVED === $status && null === $answer ) {
			throw new InvalidArgumentException( 'Resolved import decisions must include an answer.' );
		}

		$this->key     = $key;
		$this->prompt  = $prompt;
		$this->options = $options;
		$this->status  = $status;
		$this->answer  = $answer;
	}

	/**
	 * Creates a pending decision.
	 *
	 * @param string              $key     Decision key unique within a session.
	 * @param string              $prompt  Prompt shown to the user.
	 * @param array<string,mixed> $options Structured options for the user.
	 * @return self
	 */
	public static function pending( $key, $prompt, array $options ) {
		return new self( $key, $prompt, $options, self::STATUS_PENDING );
	}

	/**
	 * Recreates a decision from storage.
	 *
	 * @param array<string,mixed> $data Stored decision data.
	 * @return self
	 * @throws InvalidArgumentException When stored decision data is invalid.
	 */
	public static function from_array( array $data ) {
		foreach ( array( 'key', 'prompt', 'options', 'status' ) as $required_key ) {
			if ( ! array_key_exists( $required_key, $data ) ) {
				throw new InvalidArgumentException( 'Import decision data is missing a required key.' );
			}
		}

		if ( ! is_array( $data['options'] ) ) {
			throw new InvalidArgumentException( 'Import decision options must be an array.' );
		}

		$answer = isset( $data['answer'] ) ? $data['answer'] : null;

		if ( null !== $answer && ! is_array( $answer ) ) {
			throw new InvalidArgumentException( 'Import decision answer must be an array.' );
		}

		return new self(
			(string) $data['key'],
			(string) $data['prompt'],
			$data['options'],
			(string) $data['status'],
			$answer
		);
	}

	/**
	 * Returns a resolved copy of the decision.
	 *
	 * @param array<string,mixed> $answer Structured answer.
	 * @return self
	 */
	public function resolve( array $answer ) {
		return new self( $this->key, $this->prompt, $this->options, self::STATUS_RESOLVED, $answer );
	}

	/**
	 * Returns the decision key.
	 *
	 * @return string
	 */
	public function get_key() {
		return $this->key;
	}

	/**
	 * Returns the decision prompt.
	 *
	 * @return string
	 */
	public function get_prompt() {
		return $this->prompt;
	}

	/**
	 * Returns structured options.
	 *
	 * @return array<string,mixed>
	 */
	public function get_options() {
		return $this->options;
	}

	/**
	 * Returns the decision status.
	 *
	 * @return string
	 */
	public function get_status() {
		return $this->status;
	}

	/**
	 * Returns the structured answer, if resolved.
	 *
	 * @return array<string,mixed>|null
	 */
	public function get_answer() {
		return $this->answer;
	}

	/**
	 * Converts the decision to a storage-friendly array.
	 *
	 * @return array{key:string,prompt:string,options:array<string,mixed>,status:string,answer:array<string,mixed>|null}
	 */
	public function to_array() {
		return array(
			'key'     => $this->key,
			'prompt'  => $this->prompt,
			'options' => $this->options,
			'status'  => $this->status,
			'answer'  => $this->answer,
		);
	}
}
