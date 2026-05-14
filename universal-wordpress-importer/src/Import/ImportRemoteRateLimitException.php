<?php
/**
 * Retryable remote rate-limit diagnostic.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

use RuntimeException;

/**
 * Describes a remote response that should be retried after a backoff window.
 */
final class ImportRemoteRateLimitException extends RuntimeException {
	/**
	 * Remote URL that was rate limited.
	 *
	 * @var string
	 */
	private $url;

	/**
	 * HTTP status code.
	 *
	 * @var int
	 */
	private $status_code;

	/**
	 * Raw Retry-After header, when present.
	 *
	 * @var string
	 */
	private $retry_after_header;

	/**
	 * Parsed retry delay in seconds.
	 *
	 * @var int
	 */
	private $retry_after_seconds;

	/**
	 * Constructor.
	 *
	 * @param string $url                 Remote URL.
	 * @param int    $status_code         HTTP status code.
	 * @param string $retry_after_header  Raw Retry-After header.
	 * @param int    $retry_after_seconds Parsed retry delay in seconds.
	 */
	public function __construct( $url, $status_code, $retry_after_header, $retry_after_seconds ) {
		$this->url                 = (string) $url;
		$this->status_code         = (int) $status_code;
		$this->retry_after_header  = trim( (string) $retry_after_header );
		$this->retry_after_seconds = max( 1, (int) $retry_after_seconds );

		$message = 'Remote URL request returned HTTP ' . $this->status_code . ' rate limit; retry after ' . $this->retry_after_seconds . ' seconds.';

		if ( '' !== $this->retry_after_header ) {
			$message .= ' Retry-After: ' . $this->retry_after_header . '.';
		}

		parent::__construct( $message );
	}

	/**
	 * Returns the rate-limited URL.
	 *
	 * @return string
	 */
	public function get_url() {
		return $this->url;
	}

	/**
	 * Returns the HTTP status code.
	 *
	 * @return int
	 */
	public function get_status_code() {
		return $this->status_code;
	}

	/**
	 * Returns the raw Retry-After header.
	 *
	 * @return string
	 */
	public function get_retry_after_header() {
		return $this->retry_after_header;
	}

	/**
	 * Returns the parsed retry delay in seconds.
	 *
	 * @return int
	 */
	public function get_retry_after_seconds() {
		return $this->retry_after_seconds;
	}
}
