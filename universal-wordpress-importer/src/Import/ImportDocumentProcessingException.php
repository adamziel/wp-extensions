<?php
/**
 * Document processing exception with durable diagnostics.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

use RuntimeException;

/**
 * Carries source-item metadata that should be persisted with a processing error.
 */
final class ImportDocumentProcessingException extends RuntimeException {
	/**
	 * Durable diagnostic metadata.
	 *
	 * @var array<string,mixed>
	 */
	private $metadata;

	/**
	 * Constructor.
	 *
	 * @param string              $message  Human-readable error.
	 * @param array<string,mixed> $metadata Diagnostic metadata.
	 */
	public function __construct( $message, array $metadata = array() ) {
		parent::__construct( $message );

		$this->metadata = $metadata;
	}

	/**
	 * Returns durable diagnostic metadata.
	 *
	 * @return array<string,mixed>
	 */
	public function get_metadata() {
		return $this->metadata;
	}
}
