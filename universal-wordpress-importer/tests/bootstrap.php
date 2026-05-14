<?php
/**
 * PHPUnit bootstrap.
 *
 * @package UniversalImporter
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! function_exists( 'wp_remote_get' ) ) {
	/**
	 * Test double for the WordPress HTTP API.
	 *
	 * @param string $url  URL.
	 * @param array  $args Request args.
	 * @return array
	 */
	function wp_remote_get( $url, $args = array() ) {
		return \UniversalImporter\Tests\Unit\Import\WordPressRemoteContentFetcherWpStub::request( $url, $args );
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Test double for WordPress error checks.
	 *
	 * @param mixed $response Response.
	 * @return bool
	 */
	function is_wp_error( $response ) {
		unset( $response );

		return false;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	/**
	 * Test double for response code retrieval.
	 *
	 * @param array $response Response.
	 * @return int
	 */
	function wp_remote_retrieve_response_code( $response ) {
		return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	/**
	 * Test double for response body retrieval.
	 *
	 * @param array $response Response.
	 * @return string
	 */
	function wp_remote_retrieve_body( $response ) {
		return isset( $response['body'] ) ? (string) $response['body'] : '';
	}
}

if ( ! function_exists( 'wp_remote_retrieve_headers' ) ) {
	/**
	 * Test double for response header retrieval.
	 *
	 * @param array $response Response.
	 * @return array
	 */
	function wp_remote_retrieve_headers( $response ) {
		return isset( $response['headers'] ) && is_array( $response['headers'] ) ? $response['headers'] : array();
	}
}
