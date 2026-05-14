<?php
/**
 * Fake remote content fetcher.
 *
 * @package UniversalImporter\Tests\Unit\Import
 */

namespace UniversalImporter\Tests\Unit\Import;

use RuntimeException;
use UniversalImporter\Import\ImportRemoteContentFetcherInterface;
use UniversalImporter\Import\ImportRemoteRateLimitException;

/**
 * Serves queued remote JSON/text responses for runner tests.
 */
final class FakeRemoteContentFetcher implements ImportRemoteContentFetcherInterface {
	/**
	 * JSON responses keyed by URL.
	 *
	 * @var array<string,mixed>
	 */
	private $json = array();

	/**
	 * JSON errors keyed by URL.
	 *
	 * @var array<string,string>
	 */
	private $json_errors = array();

	/**
	 * JSON rate-limit diagnostics keyed by URL.
	 *
	 * @var array<string,ImportRemoteRateLimitException>
	 */
	private $json_rate_limits = array();

	/**
	 * Text responses keyed by URL.
	 *
	 * @var array<string,string>
	 */
	private $text = array();

	/**
	 * Text errors keyed by URL.
	 *
	 * @var array<string,string>
	 */
	private $text_errors = array();

	/**
	 * Text rate-limit diagnostics keyed by URL.
	 *
	 * @var array<string,ImportRemoteRateLimitException>
	 */
	private $text_rate_limits = array();

	/**
	 * Text response headers keyed by URL.
	 *
	 * @var array<string,array<string,string>>
	 */
	private $text_headers = array();

	/**
	 * Requested URLs.
	 *
	 * @var array<int,string>
	 */
	private $requested_urls = array();

	/**
	 * Adds a JSON response.
	 *
	 * @param string $url  URL.
	 * @param mixed  $data Response data.
	 * @return void
	 */
	public function add_json( $url, $data ) {
		$this->json[ $url ] = $data;
	}

	/**
	 * Adds a JSON fetch error.
	 *
	 * @param string $url     URL.
	 * @param string $message Error message.
	 * @return void
	 */
	public function add_json_error( $url, $message ) {
		$this->json_errors[ $url ] = (string) $message;
	}

	/**
	 * Adds a JSON rate-limit response.
	 *
	 * @param string $url                 URL.
	 * @param int    $status_code         HTTP status code.
	 * @param string $retry_after_header  Retry-After header.
	 * @param int    $retry_after_seconds Parsed retry delay.
	 * @return void
	 */
	public function add_json_rate_limit( $url, $status_code, $retry_after_header, $retry_after_seconds ) {
		$this->json_rate_limits[ $url ] = new ImportRemoteRateLimitException( $url, $status_code, $retry_after_header, $retry_after_seconds );
	}

	/**
	 * Adds a text response.
	 *
	 * @param string               $url     URL.
	 * @param string               $body    Body.
	 * @param array<string,string> $headers Response headers.
	 * @return void
	 */
	public function add_text( $url, $body, array $headers = array() ) {
		$this->text[ $url ]         = (string) $body;
		$this->text_headers[ $url ] = $headers;
	}

	/**
	 * Adds a text fetch error.
	 *
	 * @param string $url     URL.
	 * @param string $message Error message.
	 * @return void
	 */
	public function add_text_error( $url, $message ) {
		$this->text_errors[ $url ] = (string) $message;
	}

	/**
	 * Adds a text rate-limit response.
	 *
	 * @param string $url                 URL.
	 * @param int    $status_code         HTTP status code.
	 * @param string $retry_after_header  Retry-After header.
	 * @param int    $retry_after_seconds Parsed retry delay.
	 * @return void
	 */
	public function add_text_rate_limit( $url, $status_code, $retry_after_header, $retry_after_seconds ) {
		$this->text_rate_limits[ $url ] = new ImportRemoteRateLimitException( $url, $status_code, $retry_after_header, $retry_after_seconds );
	}

	/**
	 * Fetches and decodes a remote JSON document.
	 *
	 * @param string $url Remote URL.
	 * @return array<string,mixed>|array<int,mixed>
	 * @throws RuntimeException When no fake response exists.
	 */
	public function fetch_json( $url ) {
		$this->requested_urls[] = $url;

		if ( array_key_exists( $url, $this->json_errors ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Unit-test diagnostics are not rendered directly.
			throw new RuntimeException( $this->json_errors[ $url ] );
		}

		if ( array_key_exists( $url, $this->json_rate_limits ) ) {
			throw $this->json_rate_limits[ $url ];
		}

		if ( ! array_key_exists( $url, $this->json ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Unit-test diagnostics are not rendered directly.
			throw new RuntimeException( 'No fake JSON response for ' . $url . '.' );
		}

		return $this->json[ $url ];
	}

	/**
	 * Fetches remote text content.
	 *
	 * @param string $url Remote URL.
	 * @return array{body:string,headers:array<string,string>,status_code:int}
	 * @throws RuntimeException When no fake response exists.
	 */
	public function fetch_text( $url ) {
		$this->requested_urls[] = $url;

		if ( array_key_exists( $url, $this->text_errors ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Unit-test diagnostics are not rendered directly.
			throw new RuntimeException( $this->text_errors[ $url ] );
		}

		if ( array_key_exists( $url, $this->text_rate_limits ) ) {
			throw $this->text_rate_limits[ $url ];
		}

		if ( ! array_key_exists( $url, $this->text ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Unit-test diagnostics are not rendered directly.
			throw new RuntimeException( 'No fake text response for ' . $url . '.' );
		}

		return array(
			'body'        => $this->text[ $url ],
			'headers'     => isset( $this->text_headers[ $url ] ) ? $this->text_headers[ $url ] : array(),
			'status_code' => 200,
		);
	}

	/**
	 * Returns requested URLs in order.
	 *
	 * @return array<int,string>
	 */
	public function get_requested_urls() {
		return $this->requested_urls;
	}
}
