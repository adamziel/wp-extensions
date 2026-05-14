<?php
/**
 * Durable source tree item model.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

use InvalidArgumentException;

/**
 * Represents one discovered import source tree node.
 */
final class ImportSourceItem {
	const TYPE_FILE      = 'file';
	const TYPE_DIRECTORY = 'directory';

	const STATUS_QUEUED     = 'queued';
	const STATUS_PROCESSING = 'processing';
	const STATUS_DISCOVERED = 'discovered';
	const STATUS_IMPORTED   = 'imported';
	const STATUS_SKIPPED    = 'skipped';
	const STATUS_FAILED     = 'failed';

	/**
	 * Valid item types.
	 *
	 * @var array<string,bool>
	 */
	private static $valid_types = array(
		self::TYPE_FILE      => true,
		self::TYPE_DIRECTORY => true,
	);

	/**
	 * Valid item statuses.
	 *
	 * @var array<string,bool>
	 */
	private static $valid_statuses = array(
		self::STATUS_QUEUED     => true,
		self::STATUS_PROCESSING => true,
		self::STATUS_DISCOVERED => true,
		self::STATUS_IMPORTED   => true,
		self::STATUS_SKIPPED    => true,
		self::STATUS_FAILED     => true,
	);

	/**
	 * Session id.
	 *
	 * @var ImportSessionId
	 */
	private $session_id;

	/**
	 * Stable key within the session.
	 *
	 * @var string
	 */
	private $item_key;

	/**
	 * Parent item key, if known.
	 *
	 * @var string|null
	 */
	private $parent_key;

	/**
	 * Source URI or absolute local path.
	 *
	 * @var string
	 */
	private $source_uri;

	/**
	 * Path relative to the import root.
	 *
	 * @var string
	 */
	private $relative_path;

	/**
	 * Item type.
	 *
	 * @var string
	 */
	private $type;

	/**
	 * Processing status.
	 *
	 * @var string
	 */
	private $status;

	/**
	 * Structured metadata.
	 *
	 * @var array<string,mixed>
	 */
	private $metadata;

	/**
	 * Constructor.
	 *
	 * @param ImportSessionId     $session_id    Session id.
	 * @param string              $item_key      Stable item key.
	 * @param string|null         $parent_key    Parent item key.
	 * @param string              $source_uri    Source URI.
	 * @param string              $relative_path Relative path.
	 * @param string              $type          Item type.
	 * @param string              $status        Item status.
	 * @param array<string,mixed> $metadata      Structured metadata.
	 * @throws InvalidArgumentException When fields are invalid.
	 */
	private function __construct( ImportSessionId $session_id, $item_key, $parent_key, $source_uri, $relative_path, $type, $status, array $metadata ) {
		$item_key      = trim( (string) $item_key );
		$parent_key    = null === $parent_key ? null : trim( (string) $parent_key );
		$source_uri    = (string) $source_uri;
		$relative_path = (string) $relative_path;
		$type          = (string) $type;
		$status        = (string) $status;

		if ( '' === $item_key ) {
			throw new InvalidArgumentException( 'Source item key cannot be empty.' );
		}

		if ( '' === $source_uri ) {
			throw new InvalidArgumentException( 'Source item URI cannot be empty.' );
		}

		if ( ! isset( self::$valid_types[ $type ] ) ) {
			throw new InvalidArgumentException( 'Invalid source item type.' );
		}

		if ( ! isset( self::$valid_statuses[ $status ] ) ) {
			throw new InvalidArgumentException( 'Invalid source item status.' );
		}

		$this->session_id    = $session_id;
		$this->item_key      = $item_key;
		$this->parent_key    = '' === $parent_key ? null : $parent_key;
		$this->source_uri    = $source_uri;
		$this->relative_path = $relative_path;
		$this->type          = $type;
		$this->status        = $status;
		$this->metadata      = $metadata;
	}

	/**
	 * Creates a queued item.
	 *
	 * @param ImportSessionId     $session_id    Session id.
	 * @param string              $item_key      Stable item key.
	 * @param string|null         $parent_key    Parent item key.
	 * @param string              $source_uri    Source URI.
	 * @param string              $relative_path Relative path.
	 * @param string              $type          Item type.
	 * @param array<string,mixed> $metadata      Structured metadata.
	 * @return self
	 */
	public static function queued( ImportSessionId $session_id, $item_key, $parent_key, $source_uri, $relative_path, $type, array $metadata = array() ) {
		return new self( $session_id, $item_key, $parent_key, $source_uri, $relative_path, $type, self::STATUS_QUEUED, $metadata );
	}

	/**
	 * Recreates an item from storage.
	 *
	 * @param array<string,mixed> $data Stored item data.
	 * @return self
	 * @throws InvalidArgumentException When stored item data is invalid.
	 */
	public static function from_array( array $data ) {
		foreach ( array( 'session_id', 'item_key', 'source_uri', 'relative_path', 'type', 'status', 'metadata' ) as $required_key ) {
			if ( ! array_key_exists( $required_key, $data ) ) {
				throw new InvalidArgumentException( 'Source item data is missing a required key.' );
			}
		}

		if ( ! is_array( $data['metadata'] ) ) {
			throw new InvalidArgumentException( 'Source item metadata must be an array.' );
		}

		return new self(
			ImportSessionId::from_string( (string) $data['session_id'] ),
			(string) $data['item_key'],
			isset( $data['parent_key'] ) ? $data['parent_key'] : null,
			(string) $data['source_uri'],
			(string) $data['relative_path'],
			(string) $data['type'],
			(string) $data['status'],
			$data['metadata']
		);
	}

	/**
	 * Returns a copy with a new status.
	 *
	 * @param string $status New status.
	 * @return self
	 */
	public function with_status( $status ) {
		return new self( $this->session_id, $this->item_key, $this->parent_key, $this->source_uri, $this->relative_path, $this->type, $status, $this->metadata );
	}

	/**
	 * Returns a copy with merged metadata.
	 *
	 * @param array<string,mixed> $metadata Metadata to merge.
	 * @return self
	 */
	public function with_metadata( array $metadata ) {
		return new self( $this->session_id, $this->item_key, $this->parent_key, $this->source_uri, $this->relative_path, $this->type, $this->status, array_merge( $this->metadata, $metadata ) );
	}

	/**
	 * Returns a copy with replaced metadata.
	 *
	 * @param array<string,mixed> $metadata Complete metadata replacement.
	 * @return self
	 */
	public function with_replaced_metadata( array $metadata ) {
		return new self( $this->session_id, $this->item_key, $this->parent_key, $this->source_uri, $this->relative_path, $this->type, $this->status, $metadata );
	}

	/**
	 * Returns the session id.
	 *
	 * @return ImportSessionId
	 */
	public function get_session_id() {
		return $this->session_id;
	}

	/**
	 * Returns the stable item key.
	 *
	 * @return string
	 */
	public function get_key() {
		return $this->item_key;
	}

	/**
	 * Returns the parent key, if known.
	 *
	 * @return string|null
	 */
	public function get_parent_key() {
		return $this->parent_key;
	}

	/**
	 * Returns the source URI.
	 *
	 * @return string
	 */
	public function get_source_uri() {
		return $this->source_uri;
	}

	/**
	 * Returns the relative path.
	 *
	 * @return string
	 */
	public function get_relative_path() {
		return $this->relative_path;
	}

	/**
	 * Returns the type.
	 *
	 * @return string
	 */
	public function get_type() {
		return $this->type;
	}

	/**
	 * Returns the status.
	 *
	 * @return string
	 */
	public function get_status() {
		return $this->status;
	}

	/**
	 * Returns metadata.
	 *
	 * @return array<string,mixed>
	 */
	public function get_metadata() {
		return $this->metadata;
	}

	/**
	 * Converts the item to storage-friendly data.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array() {
		return array(
			'session_id'    => $this->session_id->to_string(),
			'item_key'      => $this->item_key,
			'parent_key'    => $this->parent_key,
			'source_uri'    => $this->source_uri,
			'relative_path' => $this->relative_path,
			'type'          => $this->type,
			'status'        => $this->status,
			'metadata'      => $this->metadata,
		);
	}
}
