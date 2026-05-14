<?php
/**
 * Durable media reference model.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

use InvalidArgumentException;

/**
 * Represents one media reference found in a prepared document.
 */
final class ImportMediaReference {
	const STATUS_QUEUED   = 'queued';
	const STATUS_IMPORTED = 'imported';
	const STATUS_SKIPPED  = 'skipped';
	const STATUS_FAILED   = 'failed';

	const TYPE_IMAGE = 'image';
	const TYPE_AUDIO = 'audio';
	const TYPE_VIDEO = 'video';
	const TYPE_FILE  = 'file';

	/**
	 * Valid statuses.
	 *
	 * @var array<string,bool>
	 */
	private static $valid_statuses = array(
		self::STATUS_QUEUED   => true,
		self::STATUS_IMPORTED => true,
		self::STATUS_SKIPPED  => true,
		self::STATUS_FAILED   => true,
	);

	/**
	 * Valid media types.
	 *
	 * @var array<string,bool>
	 */
	private static $valid_types = array(
		self::TYPE_IMAGE => true,
		self::TYPE_AUDIO => true,
		self::TYPE_VIDEO => true,
		self::TYPE_FILE  => true,
	);

	/**
	 * Session id.
	 *
	 * @var ImportSessionId
	 */
	private $session_id;

	/**
	 * Stable reference key.
	 *
	 * @var string
	 */
	private $reference_key;

	/**
	 * Source item key that contained this reference.
	 *
	 * @var string
	 */
	private $source_item_key;

	/**
	 * Original URL/path from content.
	 *
	 * @var string
	 */
	private $original_url;

	/**
	 * Resolved source URI to import later.
	 *
	 * @var string
	 */
	private $resolved_source_uri;

	/**
	 * Media type.
	 *
	 * @var string
	 */
	private $media_type;

	/**
	 * Queue status.
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
	 * @param ImportSessionId     $session_id          Session id.
	 * @param string              $reference_key       Stable reference key.
	 * @param string              $source_item_key     Source item key.
	 * @param string              $original_url        Original URL/path.
	 * @param string              $resolved_source_uri Resolved source URI.
	 * @param string              $media_type          Media type.
	 * @param string              $status              Queue status.
	 * @param array<string,mixed> $metadata            Structured metadata.
	 * @throws InvalidArgumentException When fields are invalid.
	 */
	private function __construct( ImportSessionId $session_id, $reference_key, $source_item_key, $original_url, $resolved_source_uri, $media_type, $status, array $metadata ) {
		$reference_key       = trim( (string) $reference_key );
		$source_item_key     = trim( (string) $source_item_key );
		$original_url        = trim( (string) $original_url );
		$resolved_source_uri = trim( (string) $resolved_source_uri );
		$media_type          = trim( (string) $media_type );
		$status              = trim( (string) $status );

		if ( '' === $reference_key ) {
			throw new InvalidArgumentException( 'Media reference key cannot be empty.' );
		}

		if ( '' === $source_item_key ) {
			throw new InvalidArgumentException( 'Media reference source item key cannot be empty.' );
		}

		if ( '' === $original_url ) {
			throw new InvalidArgumentException( 'Media reference original URL cannot be empty.' );
		}

		if ( '' === $resolved_source_uri ) {
			throw new InvalidArgumentException( 'Media reference resolved source URI cannot be empty.' );
		}

		if ( ! isset( self::$valid_types[ $media_type ] ) ) {
			throw new InvalidArgumentException( 'Invalid media reference type.' );
		}

		if ( ! isset( self::$valid_statuses[ $status ] ) ) {
			throw new InvalidArgumentException( 'Invalid media reference status.' );
		}

		$this->session_id          = $session_id;
		$this->reference_key       = $reference_key;
		$this->source_item_key     = $source_item_key;
		$this->original_url        = $original_url;
		$this->resolved_source_uri = $resolved_source_uri;
		$this->media_type          = $media_type;
		$this->status              = $status;
		$this->metadata            = $metadata;
	}

	/**
	 * Creates a queued media reference.
	 *
	 * @param ImportSessionId     $session_id          Session id.
	 * @param string              $reference_key       Stable reference key.
	 * @param string              $source_item_key     Source item key.
	 * @param string              $original_url        Original URL/path.
	 * @param string              $resolved_source_uri Resolved source URI.
	 * @param string              $media_type          Media type.
	 * @param array<string,mixed> $metadata            Structured metadata.
	 * @return self
	 */
	public static function queued( ImportSessionId $session_id, $reference_key, $source_item_key, $original_url, $resolved_source_uri, $media_type, array $metadata = array() ) {
		return new self( $session_id, $reference_key, $source_item_key, $original_url, $resolved_source_uri, $media_type, self::STATUS_QUEUED, $metadata );
	}

	/**
	 * Recreates a media reference from storage.
	 *
	 * @param array<string,mixed> $data Stored reference data.
	 * @return self
	 * @throws InvalidArgumentException When stored data is invalid.
	 */
	public static function from_array( array $data ) {
		foreach ( array( 'session_id', 'reference_key', 'source_item_key', 'original_url', 'resolved_source_uri', 'media_type', 'status', 'metadata' ) as $required_key ) {
			if ( ! array_key_exists( $required_key, $data ) ) {
				throw new InvalidArgumentException( 'Media reference data is missing a required key.' );
			}
		}

		if ( ! is_array( $data['metadata'] ) ) {
			throw new InvalidArgumentException( 'Media reference metadata must be an array.' );
		}

		return new self(
			ImportSessionId::from_string( (string) $data['session_id'] ),
			(string) $data['reference_key'],
			(string) $data['source_item_key'],
			(string) $data['original_url'],
			(string) $data['resolved_source_uri'],
			(string) $data['media_type'],
			(string) $data['status'],
			$data['metadata']
		);
	}

	/**
	 * Returns a copy marked as imported.
	 *
	 * @param int    $attachment_id  WordPress attachment id.
	 * @param string $attachment_url Public attachment URL.
	 * @param string $source_hash    Hash of the imported source file.
	 * @return self
	 */
	public function mark_imported( $attachment_id, $attachment_url, $source_hash ) {
		$metadata                       = $this->metadata;
		$metadata['attachment_id']      = (int) $attachment_id;
		$metadata['attachment_url']     = (string) $attachment_url;
		$metadata['source_hash']        = (string) $source_hash;
		$metadata['media_imported_at']  = gmdate( 'Y-m-d H:i:s' );
		$metadata['media_import_state'] = 'imported';

		return new self(
			$this->session_id,
			$this->reference_key,
			$this->source_item_key,
			$this->original_url,
			$this->resolved_source_uri,
			$this->media_type,
			self::STATUS_IMPORTED,
			$metadata
		);
	}

	/**
	 * Returns a copy marked as failed.
	 *
	 * @param string $message Failure diagnostic.
	 * @return self
	 */
	public function mark_failed( $message ) {
		$metadata                       = $this->metadata;
		$metadata['failure_message']    = (string) $message;
		$metadata['media_import_state'] = 'failed';

		return new self(
			$this->session_id,
			$this->reference_key,
			$this->source_item_key,
			$this->original_url,
			$this->resolved_source_uri,
			$this->media_type,
			self::STATUS_FAILED,
			$metadata
		);
	}

	/**
	 * Returns a copy marked as skipped.
	 *
	 * @param string $message Skip diagnostic.
	 * @return self
	 */
	public function mark_skipped( $message ) {
		$metadata                       = $this->metadata;
		$metadata['skip_reason']        = (string) $message;
		$metadata['media_import_state'] = 'skipped';

		return new self(
			$this->session_id,
			$this->reference_key,
			$this->source_item_key,
			$this->original_url,
			$this->resolved_source_uri,
			$this->media_type,
			self::STATUS_SKIPPED,
			$metadata
		);
	}

	/**
	 * Returns a copy with replaced structured metadata.
	 *
	 * @param array<string,mixed> $metadata Structured metadata.
	 * @return self
	 */
	public function with_metadata( array $metadata ) {
		return new self(
			$this->session_id,
			$this->reference_key,
			$this->source_item_key,
			$this->original_url,
			$this->resolved_source_uri,
			$this->media_type,
			$this->status,
			$metadata
		);
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
	 * Returns the stable reference key.
	 *
	 * @return string
	 */
	public function get_key() {
		return $this->reference_key;
	}

	/**
	 * Returns the source item key.
	 *
	 * @return string
	 */
	public function get_source_item_key() {
		return $this->source_item_key;
	}

	/**
	 * Returns the original URL/path.
	 *
	 * @return string
	 */
	public function get_original_url() {
		return $this->original_url;
	}

	/**
	 * Returns the resolved source URI.
	 *
	 * @return string
	 */
	public function get_resolved_source_uri() {
		return $this->resolved_source_uri;
	}

	/**
	 * Returns the media type.
	 *
	 * @return string
	 */
	public function get_media_type() {
		return $this->media_type;
	}

	/**
	 * Returns the queue status.
	 *
	 * @return string
	 */
	public function get_status() {
		return $this->status;
	}

	/**
	 * Returns structured metadata.
	 *
	 * @return array<string,mixed>
	 */
	public function get_metadata() {
		return $this->metadata;
	}

	/**
	 * Converts the reference to storage-friendly data.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array() {
		return array(
			'session_id'          => $this->session_id->to_string(),
			'reference_key'       => $this->reference_key,
			'source_item_key'     => $this->source_item_key,
			'original_url'        => $this->original_url,
			'resolved_source_uri' => $this->resolved_source_uri,
			'media_type'          => $this->media_type,
			'status'              => $this->status,
			'metadata'            => $this->metadata,
		);
	}
}
