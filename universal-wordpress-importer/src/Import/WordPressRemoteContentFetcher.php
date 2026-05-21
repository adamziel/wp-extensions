<?php
/**
 * WordPress HTTP remote content fetcher.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

use RuntimeException;

/**
 * Fetches remote JSON and text through the WordPress HTTP API.
 */
final class WordPressRemoteContentFetcher implements ImportRemoteContentFetcherInterface {
	const DEFAULT_TIMEOUT = 30;
	const MAX_BODY_BYTES  = 5242880;
	const AUTH_HOSTS      = 'UNIVERSAL_IMPORTER_REMOTE_AUTH_HOSTS';
	const BEARER_TOKEN    = 'UNIVERSAL_IMPORTER_REMOTE_BEARER_TOKEN';
	const BASIC_USER      = 'UNIVERSAL_IMPORTER_REMOTE_BASIC_USER';
	const BASIC_PASSWORD  = 'UNIVERSAL_IMPORTER_REMOTE_BASIC_PASSWORD';
	const GITHUB_TOKEN    = 'UNIVERSAL_IMPORTER_GITHUB_TOKEN';

	/**
	 * Fetches and decodes a remote JSON document.
	 *
	 * @param string $url Remote URL.
	 * @return array<string,mixed>|array<int,mixed>
	 * @throws RuntimeException When the request fails or JSON is invalid.
	 */
	public function fetch_json( $url ) {
		$response = $this->request( $url, 'application/json' );
		$decoded  = json_decode( $response['body'], true );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			throw new RuntimeException( 'Remote JSON response could not be decoded.' );
		}

		return $decoded;
	}

	/**
	 * Fetches remote text content.
	 *
	 * @param string $url Remote URL.
	 * @return array{body:string,headers:array<string,string>,status_code:int}
	 * @throws RuntimeException When the request fails.
	 */
	public function fetch_text( $url ) {
		return $this->request( $url, 'text/html,application/xhtml+xml,text/plain;q=0.8,*/*;q=0.1' );
	}

	/**
	 * Performs a bounded remote request.
	 *
	 * @param string $url    Remote URL.
	 * @param string $accept Accept header.
	 * @return array{body:string,headers:array<string,string>,status_code:int}
	 * @throws ImportRemoteRateLimitException When the remote server asks the importer to retry later.
	 * @throws RuntimeException When the WordPress HTTP API is unavailable or the request fails.
	 */
	private function request( $url, $accept ) {
		if ( ! function_exists( 'wp_remote_get' ) ) {
			throw new RuntimeException( 'Remote URL traversal requires the WordPress HTTP API.' );
		}

		$headers = array(
			'Accept'     => $accept,
			'User-Agent' => 'Universal-WordPress-Importer',
		);
		$auth    = $this->authorization_header_for_url( $url );

		if ( $this->is_github_api_url( $url ) ) {
			$headers['X-GitHub-Api-Version'] = '2022-11-28';
		}

		if ( '' !== $auth ) {
			$headers['Authorization'] = $auth;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => self::DEFAULT_TIMEOUT,
				'redirection'         => '' === $auth ? 5 : 0,
				'headers'             => $headers,
				'limit_response_size' => self::MAX_BODY_BYTES,
			)
		);

		if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
			throw new RuntimeException( 'Remote URL request failed: ' . $response->get_error_message() );
		}

		$status_code      = function_exists( 'wp_remote_retrieve_response_code' ) ? (int) wp_remote_retrieve_response_code( $response ) : 0;
		$response_headers = $this->headers_from_response( $response );

		if ( 200 > $status_code || 300 <= $status_code ) {
			$retry_after = isset( $response_headers['retry-after'] ) ? trim( (string) $response_headers['retry-after'] ) : '';

			if ( 403 === $status_code && $this->is_github_api_url( $url ) && $this->is_github_rate_limit_response( $response_headers ) ) {
				$retry_after = $this->github_rate_limit_retry_after( $response_headers );

				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
				throw new ImportRemoteRateLimitException( $url, $status_code, $retry_after, $this->parse_retry_after_seconds( $retry_after ) );
			}

			if ( 429 === $status_code || ( 503 === $status_code && '' !== $retry_after ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
				throw new ImportRemoteRateLimitException( $url, $status_code, $retry_after, $this->parse_retry_after_seconds( $retry_after ) );
			}

			if ( 300 <= $status_code && 400 > $status_code ) {
				$location = isset( $response_headers['location'] ) ? trim( (string) $response_headers['location'] ) : '';
				$message  = 'Remote URL request returned HTTP ' . $status_code . ' redirect';
				$message .= '' === $location ? '.' : ' to ' . $location . '.';
				$message .= '' === $auth ? ' Import the final canonical URL if the server does not resolve redirects automatically.' : ' Authenticated remote requests do not follow redirects; import the final canonical URL or configure credentials for that host.';

				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
				throw new RuntimeException( $message );
			}

			if ( in_array( $status_code, array( 401, 403 ), true ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
				throw new RuntimeException( 'Remote URL request returned HTTP ' . $status_code . '. If this source requires authentication, configure ' . self::AUTH_HOSTS . ' with the exact host and set either ' . self::BEARER_TOKEN . ' or ' . self::BASIC_USER . '/' . self::BASIC_PASSWORD . '.' );
			}

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
			throw new RuntimeException( 'Remote URL request returned HTTP ' . $status_code . '.' );
		}

		$body = function_exists( 'wp_remote_retrieve_body' ) ? (string) wp_remote_retrieve_body( $response ) : '';

		if ( '' === $body ) {
			throw new RuntimeException( 'Remote URL request returned an empty response body.' );
		}

		return array(
			'body'        => $body,
			'headers'     => $response_headers,
			'status_code' => $status_code,
		);
	}

	/**
	 * Parses a Retry-After header into a bounded delay in seconds.
	 *
	 * @param string $header Retry-After header value.
	 * @return int
	 */
	private function parse_retry_after_seconds( $header ) {
		$header = trim( (string) $header );

		if ( '' === $header ) {
			return 60;
		}

		if ( ctype_digit( $header ) ) {
			return max( 1, min( 86400, (int) $header ) );
		}

		$timestamp = strtotime( $header );

		if ( false === $timestamp ) {
			return 60;
		}

		return max( 1, min( 86400, $timestamp - time() ) );
	}

	/**
	 * Returns whether a GitHub API response exhausted the current rate limit.
	 *
	 * @param array<string,string> $headers Response headers.
	 * @return bool
	 */
	private function is_github_rate_limit_response( array $headers ) {
		return isset( $headers['x-ratelimit-remaining'] ) && '0' === trim( (string) $headers['x-ratelimit-remaining'] );
	}

	/**
	 * Builds a Retry-After compatible value from GitHub rate-limit headers.
	 *
	 * @param array<string,string> $headers Response headers.
	 * @return string
	 */
	private function github_rate_limit_retry_after( array $headers ) {
		if ( isset( $headers['retry-after'] ) && '' !== trim( (string) $headers['retry-after'] ) ) {
			return trim( (string) $headers['retry-after'] );
		}

		if ( empty( $headers['x-ratelimit-reset'] ) ) {
			return '60';
		}

		$reset = trim( (string) $headers['x-ratelimit-reset'] );
		if ( ! ctype_digit( $reset ) ) {
			return '60';
		}

		return gmdate( 'D, d M Y H:i:s', max( time() + 1, (int) $reset ) ) . ' GMT';
	}

	/**
	 * Builds an Authorization header for explicitly allowed source hosts.
	 *
	 * @param string $url Remote URL.
	 * @return string
	 */
	private function authorization_header_for_url( $url ) {
		$host = $this->host_from_url( $url );
		if ( 'api.github.com' === $host ) {
			$token = $this->configured_value( self::GITHUB_TOKEN );

			if ( '' !== $token ) {
				return 'Bearer ' . $token;
			}
		}

		if ( '' === $host || ! in_array( $host, $this->configured_auth_hosts(), true ) ) {
			return '';
		}

		$token = $this->configured_value( self::BEARER_TOKEN );

		if ( '' !== $token ) {
			return 'Bearer ' . $token;
		}

		$user     = $this->configured_value( self::BASIC_USER );
		$password = $this->configured_value( self::BASIC_PASSWORD );

		if ( '' === $user || '' === $password ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth requires base64-encoded userinfo.
		return 'Basic ' . base64_encode( $user . ':' . $password );
	}

	/**
	 * Returns whether a URL targets the GitHub REST API host.
	 *
	 * @param string $url Remote URL.
	 * @return bool
	 */
	private function is_github_api_url( $url ) {
		return 'api.github.com' === $this->host_from_url( $url );
	}

	/**
	 * Returns exact lower-case hosts allowed to receive configured credentials.
	 *
	 * @return array<int,string>
	 */
	private function configured_auth_hosts() {
		$hosts = array();

		foreach ( explode( ',', $this->configured_value( self::AUTH_HOSTS ) ) as $host ) {
			$host = strtolower( trim( (string) $host ) );

			if ( '' !== $host && ! in_array( $host, $hosts, true ) ) {
				$hosts[] = $host;
			}
		}

		return $hosts;
	}

	/**
	 * Reads a supported runtime configuration value from a constant or env var.
	 *
	 * @param string $name Configuration name.
	 * @return string
	 */
	private function configured_value( $name ) {
		if ( defined( $name ) ) {
			return trim( (string) constant( $name ) );
		}

		$value = getenv( $name );

		return false === $value ? '' : trim( (string) $value );
	}

	/**
	 * Extracts a normalized host from a URL.
	 *
	 * @param string $url Remote URL.
	 * @return string
	 */
	private function host_from_url( $url ) {
		if ( function_exists( 'wp_parse_url' ) ) {
			$parts = wp_parse_url( (string) $url );
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Unit tests can exercise this class without WordPress loaded.
			$parts = parse_url( (string) $url );
		}

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		return strtolower( (string) $parts['host'] );
	}

	/**
	 * Extracts normalized response headers when WordPress helpers are available.
	 *
	 * @param mixed $response WordPress HTTP response.
	 * @return array<string,string>
	 */
	private function headers_from_response( $response ) {
		if ( ! function_exists( 'wp_remote_retrieve_headers' ) ) {
			return array();
		}

		$headers = wp_remote_retrieve_headers( $response );
		$output  = array();

		foreach ( (array) $headers as $name => $value ) {
			$output[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}

		return $output;
	}
}
