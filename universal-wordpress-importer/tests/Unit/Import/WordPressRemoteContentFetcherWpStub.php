<?php
/**
 * WordPress HTTP API test double storage.
 *
 * @package UniversalImporter\Tests\Unit\Import
 */

namespace UniversalImporter\Tests\Unit\Import;

/**
 * Records and serves WordPress HTTP API requests for fetcher tests.
 */
final class WordPressRemoteContentFetcherWpStub {
	/**
	 * Queued WordPress HTTP responses.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private static $responses = array();

	/**
	 * Recorded requests.
	 *
	 * @var array<int,array{url:string,args:array<string,mixed>}>
	 */
	private static $requests = array();

	/**
	 * Resets queued responses and recorded requests.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$responses = array();
		self::$requests  = array();
	}

	/**
	 * Adds a fake WordPress HTTP response.
	 *
	 * @param array<string,mixed> $response Response.
	 * @return void
	 */
	public static function queue_response( array $response ) {
		self::$responses[] = $response;
	}

	/**
	 * Handles a fake wp_remote_get call.
	 *
	 * @param string              $url  URL.
	 * @param array<string,mixed> $args Request args.
	 * @return array<string,mixed>
	 */
	public static function request( $url, array $args ) {
		self::$requests[] = array(
			'url'  => $url,
			'args' => $args,
		);

		if ( empty( self::$responses ) ) {
			return array(
				'response' => array( 'code' => 500 ),
				'body'     => '',
				'headers'  => array(),
			);
		}

		$response = array_shift( self::$responses );

		if ( ! empty( $args['stream'] ) && ! empty( $args['filename'] ) && isset( $response['body'] ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Unit-test HTTP stub writes streamed responses to the requested temp file.
			file_put_contents( (string) $args['filename'], (string) $response['body'] );
		}

		return $response;
	}

	/**
	 * Returns the most recent fake request.
	 *
	 * @return array{url:string,args:array<string,mixed>}
	 */
	public static function last_request() {
		return self::$requests[ count( self::$requests ) - 1 ];
	}
}
