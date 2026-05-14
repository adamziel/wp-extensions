<?php
/**
 * Durable prepared document model.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

use InvalidArgumentException;

/**
 * Represents block-ready content prepared from a source item.
 */
final class ImportPreparedDocument {
	/**
	 * Session id.
	 *
	 * @var ImportSessionId
	 */
	private $session_id;

	/**
	 * Source item key.
	 *
	 * @var string
	 */
	private $source_item_key;

	/**
	 * Document format.
	 *
	 * @var string
	 */
	private $format;

	/**
	 * Suggested post title.
	 *
	 * @var string
	 */
	private $title;

	/**
	 * Prepared block markup.
	 *
	 * @var string
	 */
	private $block_markup;

	/**
	 * Number of top-level blocks in the prepared markup.
	 *
	 * @var int
	 */
	private $block_count;

	/**
	 * Hash of the source content and format.
	 *
	 * @var string
	 */
	private $content_hash;

	/**
	 * Additional structured metadata.
	 *
	 * @var array<string,mixed>
	 */
	private $metadata;

	/**
	 * Constructor.
	 *
	 * @param ImportSessionId     $session_id      Session id.
	 * @param string              $source_item_key Source item key.
	 * @param string              $format          Document format.
	 * @param string              $title           Document title.
	 * @param string              $block_markup    Prepared block markup.
	 * @param int                 $block_count     Number of prepared blocks.
	 * @param string              $content_hash    Source content hash.
	 * @param array<string,mixed> $metadata        Structured metadata.
	 * @throws InvalidArgumentException When fields are invalid.
	 */
	public function __construct( ImportSessionId $session_id, $source_item_key, $format, $title, $block_markup, $block_count, $content_hash, array $metadata = array() ) {
		$source_item_key = trim( (string) $source_item_key );
		$format          = trim( (string) $format );
		$title           = trim( (string) $title );
		$block_markup    = (string) $block_markup;
		$block_count     = (int) $block_count;
		$content_hash    = trim( (string) $content_hash );

		if ( '' === $source_item_key ) {
			throw new InvalidArgumentException( 'Prepared document source item key cannot be empty.' );
		}

		if ( '' === $format ) {
			throw new InvalidArgumentException( 'Prepared document format cannot be empty.' );
		}

		if ( '' === $title ) {
			throw new InvalidArgumentException( 'Prepared document title cannot be empty.' );
		}

		if ( $block_count < 0 ) {
			throw new InvalidArgumentException( 'Prepared document block count cannot be negative.' );
		}

		if ( '' === $content_hash ) {
			throw new InvalidArgumentException( 'Prepared document content hash cannot be empty.' );
		}

		$this->session_id      = $session_id;
		$this->source_item_key = $source_item_key;
		$this->format          = $format;
		$this->title           = $title;
		$this->block_markup    = $block_markup;
		$this->block_count     = $block_count;
		$this->content_hash    = $content_hash;
		$this->metadata        = $metadata;
	}

	/**
	 * Recreates a prepared document from storage.
	 *
	 * @param array<string,mixed> $data Stored document data.
	 * @return self
	 * @throws InvalidArgumentException When stored document data is invalid.
	 */
	public static function from_array( array $data ) {
		foreach ( array( 'session_id', 'source_item_key', 'format', 'title', 'block_markup', 'block_count', 'content_hash', 'metadata' ) as $required_key ) {
			if ( ! array_key_exists( $required_key, $data ) ) {
				throw new InvalidArgumentException( 'Prepared document data is missing a required key.' );
			}
		}

		if ( ! is_array( $data['metadata'] ) ) {
			throw new InvalidArgumentException( 'Prepared document metadata must be an array.' );
		}

		return new self(
			ImportSessionId::from_string( (string) $data['session_id'] ),
			(string) $data['source_item_key'],
			(string) $data['format'],
			(string) $data['title'],
			(string) $data['block_markup'],
			(int) $data['block_count'],
			(string) $data['content_hash'],
			$data['metadata']
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
	 * Returns the source item key.
	 *
	 * @return string
	 */
	public function get_source_item_key() {
		return $this->source_item_key;
	}

	/**
	 * Returns the document format.
	 *
	 * @return string
	 */
	public function get_format() {
		return $this->format;
	}

	/**
	 * Returns the document title.
	 *
	 * @return string
	 */
	public function get_title() {
		return $this->title;
	}

	/**
	 * Returns prepared block markup.
	 *
	 * @return string
	 */
	public function get_block_markup() {
		return $this->block_markup;
	}

	/**
	 * Returns the number of prepared blocks.
	 *
	 * @return int
	 */
	public function get_block_count() {
		return $this->block_count;
	}

	/**
	 * Returns the source content hash.
	 *
	 * @return string
	 */
	public function get_content_hash() {
		return $this->content_hash;
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
	 * Returns a copy with rewritten block markup and updated metadata.
	 *
	 * @param string              $block_markup Rewritten block markup.
	 * @param string              $content_hash Rewritten content hash.
	 * @param array<string,mixed> $metadata     Rewritten metadata.
	 * @return self
	 */
	public function with_rewritten_block_markup( $block_markup, $content_hash, array $metadata ) {
		return new self(
			$this->session_id,
			$this->source_item_key,
			$this->format,
			$this->title,
			(string) $block_markup,
			$this->block_count,
			(string) $content_hash,
			$metadata
		);
	}

	/**
	 * Returns a copy with updated metadata.
	 *
	 * @param array<string,mixed> $metadata Updated metadata.
	 * @return self
	 */
	public function with_metadata( array $metadata ) {
		return new self(
			$this->session_id,
			$this->source_item_key,
			$this->format,
			$this->title,
			$this->block_markup,
			$this->block_count,
			$this->content_hash,
			$metadata
		);
	}

	/**
	 * Converts the document to storage-friendly data.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array() {
		return array(
			'session_id'      => $this->session_id->to_string(),
			'source_item_key' => $this->source_item_key,
			'format'          => $this->format,
			'title'           => $this->title,
			'block_markup'    => $this->block_markup,
			'block_count'     => $this->block_count,
			'content_hash'    => $this->content_hash,
			'metadata'        => $this->metadata,
		);
	}
}
