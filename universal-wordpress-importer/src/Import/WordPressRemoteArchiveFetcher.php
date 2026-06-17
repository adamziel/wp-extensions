<?php
/**
 * WordPress HTTP remote archive fetcher.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

use RuntimeException;

/**
 * Downloads remote archives through the WordPress HTTP API.
 */
final class WordPressRemoteArchiveFetcher implements ImportRemoteArchiveFetcherInterface {
	const DEFAULT_TIMEOUT = 60;

	/**
	 * Downloads a remote archive URL to a local target path.
	 *
	 * @param string $url         Archive URL.
	 * @param string $target_path Absolute local target path.
	 * @return array<string,mixed> Fetch metadata.
	 * @throws RuntimeException When the WordPress HTTP API is unavailable or the download fails.
	 */
	public function fetch( $url, $target_path ) {
		if ( ! function_exists( 'wp_remote_get' ) ) {
			throw new RuntimeException( 'GitHub repository traversal requires the WordPress HTTP API.' );
		}

		$headers = array(
			'User-Agent' => 'Universal-WordPress-Importer',
		);

		if ( $this->is_github_api_url( $url ) ) {
			$headers['Accept']               = 'application/vnd.github+json';
			$headers['X-GitHub-Api-Version'] = '2022-11-28';
			$token                           = $this->github_token();

			if ( '' !== $token ) {
				$headers['Authorization'] = 'Bearer ' . $token;
			}
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => self::DEFAULT_TIMEOUT,
				'redirection' => 5,
				'stream'      => true,
				'filename'    => $target_path,
				'headers'     => $headers,
			)
		);

		/** @var array<string,mixed>|\WP_Error $response */
		if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) {
			if ( file_exists( $target_path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Cleans up an importer-managed failed download cache file.
				unlink( $target_path );
			}

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
			throw new RuntimeException( 'GitHub archive download failed: ' . $response->get_error_message() );
		}

		$status_code = function_exists( 'wp_remote_retrieve_response_code' ) ? (int) wp_remote_retrieve_response_code( $response ) : 0;

		if ( 200 > $status_code || 300 <= $status_code ) {
			if ( file_exists( $target_path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Cleans up an importer-managed failed download cache file.
				unlink( $target_path );
			}

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
			throw new RuntimeException( 'GitHub archive download returned HTTP ' . $status_code . '.' );
		}

		if ( ! is_file( $target_path ) || 0 === filesize( $target_path ) ) {
			throw new RuntimeException( 'GitHub archive download did not produce a usable zip file.' );
		}

		return array(
			'bytes'       => filesize( $target_path ),
			'status_code' => $status_code,
		);
	}

	/**
	 * Returns whether a URL targets the GitHub REST API host.
	 *
	 * @param string $url Remote URL.
	 * @return bool
	 */
	private function is_github_api_url( $url ) {
		if ( function_exists( 'wp_parse_url' ) ) {
			$parts = wp_parse_url( (string) $url );
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Unit tests can exercise this class without WordPress loaded.
			$parts = parse_url( (string) $url );
		}

		return is_array( $parts ) && ! empty( $parts['host'] ) && 'api.github.com' === strtolower( (string) $parts['host'] );
	}

	/**
	 * Returns an optional GitHub token from supported runtime configuration.
	 *
	 * @return string
	 */
	private function github_token() {
		if ( defined( 'UNIVERSAL_IMPORTER_GITHUB_TOKEN' ) ) {
			return trim( (string) constant( 'UNIVERSAL_IMPORTER_GITHUB_TOKEN' ) );
		}

		$env = getenv( 'UNIVERSAL_IMPORTER_GITHUB_TOKEN' );

		return false === $env ? '' : trim( (string) $env );
	}
}
