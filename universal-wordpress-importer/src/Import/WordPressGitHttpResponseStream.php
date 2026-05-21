<?php
/**
 * In-memory Git HTTP response stream.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

use WordPress\ByteStream\MemoryPipe;
use WordPress\HttpClient\Response;

/**
 * In-memory Git response stream with a php-toolkit-compatible response hook.
 */
final class WordPressGitHttpResponseStream extends MemoryPipe {
	/**
	 * HTTP response metadata.
	 *
	 * @var Response
	 */
	private $response;

	/**
	 * Constructor.
	 *
	 * @param string   $body     Response body.
	 * @param Response $response Response metadata.
	 */
	public function __construct( $body, Response $response ) {
		parent::__construct( (string) $body );

		$this->response = $response;
	}

	/**
	 * Returns response metadata.
	 *
	 * @return Response
	 */
	public function await_response() {
		return $this->response;
	}
}
