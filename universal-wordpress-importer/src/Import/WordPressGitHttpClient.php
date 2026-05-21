<?php
/**
 * WordPress HTTP adapter for php-toolkit Git requests.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

use RuntimeException;
use WordPress\HttpClient\Request;
use WordPress\HttpClient\Response;

/**
 * Sends php-toolkit Git HTTP requests through WordPress' HTTP API.
 */
final class WordPressGitHttpClient {
	/**
	 * Fetches one Git HTTP request.
	 *
	 * @param Request $request Git HTTP request.
	 * @return WordPressGitHttpResponseStream Response stream.
	 * @throws RuntimeException When the WordPress HTTP API is unavailable or the request fails.
	 */
	public function fetch( Request $request ) {
		if ( ! function_exists( 'wp_remote_request' ) ) {
			throw new RuntimeException( 'WordPress HTTP API is unavailable for GitHub repository traversal.' );
		}

		$args = array(
			'method'      => $request->method,
			'headers'     => $request->headers,
			'timeout'     => 300,
			'redirection' => 5,
			'body'        => null,
		);

		if ( null !== $request->upload_body_stream ) {
			$args['body'] = $request->upload_body_stream->consume_all();
		}

		$result = wp_remote_request( $request->url, $args );
		if ( is_wp_error( $result ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Stored as importer diagnostics, escaped by the UI.
			throw new RuntimeException( $result->get_error_message() );
		}

		$response                 = new Response( $request );
		$response->status_code    = (int) wp_remote_retrieve_response_code( $result );
		$response->headers        = $this->normalize_headers( wp_remote_retrieve_headers( $result ) );
		$body                     = (string) wp_remote_retrieve_body( $result );
		$response->total_bytes    = strlen( $body );
		$response->received_bytes = strlen( $body );

		return new WordPressGitHttpResponseStream( $body, $response );
	}

	/**
	 * Normalizes WordPress HTTP response headers for php-toolkit.
	 *
	 * @param mixed $headers WordPress response headers.
	 * @return array<string,string>
	 */
	private function normalize_headers( $headers ) {
		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$headers = $headers->getAll();
		}

		if ( ! is_array( $headers ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $headers as $name => $value ) {
			$normalized[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
		}

		return $normalized;
	}
}
