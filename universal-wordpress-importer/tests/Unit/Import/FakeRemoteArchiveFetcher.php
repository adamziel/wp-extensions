<?php
/**
 * Fake remote archive fetcher.
 *
 * @package UniversalImporter\Tests\Unit\Import
 */

namespace UniversalImporter\Tests\Unit\Import;

use RuntimeException;
use UniversalImporter\Import\ImportRemoteArchiveFetcherInterface;

/**
 * Copies a fixture archive or throws a configured failure for runner tests.
 */
final class FakeRemoteArchiveFetcher implements ImportRemoteArchiveFetcherInterface {
	/**
	 * Fixture archive to copy.
	 *
	 * @var string|null
	 */
	private $archive_path;

	/**
	 * Failure message or per-request failure messages.
	 *
	 * @var string|array<int,string>
	 */
	private $failure_message;

	/**
	 * Requested URL.
	 *
	 * @var string|null
	 */
	private $requested_url;

	/**
	 * Requested URLs.
	 *
	 * @var array<int,string>
	 */
	private $requested_urls = array();

	/**
	 * Current request index.
	 *
	 * @var int
	 */
	private $request_index = 0;

	/**
	 * Constructor.
	 *
	 * @param string|null              $archive_path    Fixture archive path.
	 * @param string|array<int,string> $failure_message Optional failure message or per-request messages.
	 */
	public function __construct( $archive_path, $failure_message = '' ) {
		$this->archive_path    = $archive_path;
		$this->failure_message = $failure_message;
	}

	/**
	 * Downloads a remote archive URL to a local target path.
	 *
	 * @param string $url         Archive URL.
	 * @param string $target_path Absolute local target path.
	 * @return array<string,mixed>
	 * @throws RuntimeException When configured to fail.
	 */
	public function fetch( $url, $target_path ) {
		$this->requested_url    = $url;
		$this->requested_urls[] = $url;
		$failure_message        = is_array( $this->failure_message )
			? ( isset( $this->failure_message[ $this->request_index ] ) ? $this->failure_message[ $this->request_index ] : '' )
			: $this->failure_message;
		++$this->request_index;

		if ( '' !== $failure_message ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Unit-test diagnostics are not rendered directly.
			throw new RuntimeException( $failure_message );
		}

		copy( $this->archive_path, $target_path );

		return array( 'bytes' => filesize( $target_path ) );
	}

	/**
	 * Returns the last requested URL.
	 *
	 * @return string|null
	 */
	public function get_requested_url() {
		return $this->requested_url;
	}

	/**
	 * Returns all requested URLs.
	 *
	 * @return array<int,string>
	 */
	public function get_requested_urls() {
		return $this->requested_urls;
	}
}
