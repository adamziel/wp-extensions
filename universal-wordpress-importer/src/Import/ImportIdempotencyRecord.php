<?php
/**
 * Import idempotency record model.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

use InvalidArgumentException;

/**
 * Maps a deterministic import operation key to the WordPress resource it created.
 */
final class ImportIdempotencyRecord {
	/**
	 * Deterministic operation key.
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Created resource type.
	 *
	 * @var string
	 */
	private $resource_type;

	/**
	 * Created resource id.
	 *
	 * @var string
	 */
	private $resource_id;

	/**
	 * Optional payload hash used to detect changed source data.
	 *
	 * @var string
	 */
	private $payload_hash;

	/**
	 * Constructor.
	 *
	 * @param string $key           Deterministic operation key.
	 * @param string $resource_type Created resource type.
	 * @param string $resource_id   Created resource id.
	 * @param string $payload_hash  Optional payload hash.
	 * @throws InvalidArgumentException When record fields are invalid.
	 */
	public function __construct( $key, $resource_type, $resource_id, $payload_hash = '' ) {
		$key           = trim( (string) $key );
		$resource_type = trim( (string) $resource_type );
		$resource_id   = trim( (string) $resource_id );
		$payload_hash  = trim( (string) $payload_hash );

		if ( '' === $key ) {
			throw new InvalidArgumentException( 'Idempotency key cannot be empty.' );
		}

		if ( '' === $resource_type ) {
			throw new InvalidArgumentException( 'Idempotency resource type cannot be empty.' );
		}

		if ( '' === $resource_id ) {
			throw new InvalidArgumentException( 'Idempotency resource id cannot be empty.' );
		}

		$this->key           = $key;
		$this->resource_type = $resource_type;
		$this->resource_id   = $resource_id;
		$this->payload_hash  = $payload_hash;
	}

	/**
	 * Recreates a record from storage.
	 *
	 * @param array<string,mixed> $data Stored record data.
	 * @return self
	 * @throws InvalidArgumentException When stored record data is invalid.
	 */
	public static function from_array( array $data ) {
		foreach ( array( 'key', 'resource_type', 'resource_id' ) as $required_key ) {
			if ( ! isset( $data[ $required_key ] ) ) {
				throw new InvalidArgumentException( 'Idempotency record is missing a required key.' );
			}
		}

		$payload_hash = isset( $data['payload_hash'] ) ? (string) $data['payload_hash'] : '';

		return new self(
			(string) $data['key'],
			(string) $data['resource_type'],
			(string) $data['resource_id'],
			$payload_hash
		);
	}

	/**
	 * Returns the deterministic operation key.
	 *
	 * @return string
	 */
	public function get_key() {
		return $this->key;
	}

	/**
	 * Returns the created resource type.
	 *
	 * @return string
	 */
	public function get_resource_type() {
		return $this->resource_type;
	}

	/**
	 * Returns the created resource id.
	 *
	 * @return string
	 */
	public function get_resource_id() {
		return $this->resource_id;
	}

	/**
	 * Returns the optional payload hash.
	 *
	 * @return string
	 */
	public function get_payload_hash() {
		return $this->payload_hash;
	}

	/**
	 * Converts the record to a storage-friendly array.
	 *
	 * @return array{key:string,resource_type:string,resource_id:string,payload_hash:string}
	 */
	public function to_array() {
		return array(
			'key'           => $this->key,
			'resource_type' => $this->resource_type,
			'resource_id'   => $this->resource_id,
			'payload_hash'  => $this->payload_hash,
		);
	}
}
