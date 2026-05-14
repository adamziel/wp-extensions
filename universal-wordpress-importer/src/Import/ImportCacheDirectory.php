<?php
/**
 * Importer-managed cache directory.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Import cache cleanup and directory creation intentionally operate on importer-owned files.

use InvalidArgumentException;
use RuntimeException;

/**
 * Resolves durable cache paths for importer-owned transient files.
 */
final class ImportCacheDirectory {
	const DEFAULT_BASENAME = 'universal-importer-cache';

	/**
	 * Absolute cache root.
	 *
	 * @var string
	 */
	private $root;

	/**
	 * Cache backend label for diagnostics.
	 *
	 * @var string
	 */
	private $backend;

	/**
	 * Deferred diagnostic when the cache cannot be used.
	 *
	 * @var string
	 */
	private $unavailable_reason;

	/**
	 * Constructor.
	 *
	 * @param string $root               Absolute cache root.
	 * @param string $backend            Cache backend label.
	 * @param string $unavailable_reason Optional deferred diagnostic.
	 * @throws InvalidArgumentException When the cache root is invalid.
	 */
	public function __construct( $root, $backend = 'custom', $unavailable_reason = '' ) {
		$root               = rtrim( (string) $root, '/\\' );
		$backend            = trim( (string) $backend );
		$unavailable_reason = trim( (string) $unavailable_reason );

		if ( '' === $root && '' === $unavailable_reason ) {
			throw new InvalidArgumentException( 'Importer cache root cannot be empty.' );
		}

		$this->root               = $root;
		$this->backend            = '' === $backend ? 'custom' : $backend;
		$this->unavailable_reason = $unavailable_reason;
	}

	/**
	 * Creates a cache directory from the current WordPress runtime.
	 *
	 * @return self
	 */
	public static function from_environment() {
		if ( function_exists( 'wp_upload_dir' ) ) {
			return self::from_wordpress_upload_dir( wp_upload_dir() );
		}

		return new self( rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . self::DEFAULT_BASENAME, 'temporary-fallback' );
	}

	/**
	 * Creates a cache directory from a wp_upload_dir() result.
	 *
	 * @param array<string,mixed> $upload WordPress upload directory data.
	 * @return self
	 */
	public static function from_wordpress_upload_dir( array $upload ) {
		if ( ! empty( $upload['error'] ) ) {
			return new self( '', 'wordpress-uploads', 'WordPress upload directory is unavailable for importer cache: ' . (string) $upload['error'] );
		}

		if ( empty( $upload['basedir'] ) || ! is_string( $upload['basedir'] ) ) {
			return new self( '', 'wordpress-uploads', 'WordPress upload directory is unavailable for importer cache: missing basedir.' );
		}

		return new self( rtrim( $upload['basedir'], '/\\' ) . DIRECTORY_SEPARATOR . self::DEFAULT_BASENAME, 'wordpress-uploads' );
	}

	/**
	 * Returns the cache root.
	 *
	 * @return string
	 * @throws RuntimeException When the cache is unavailable.
	 */
	public function get_root() {
		$this->assert_available();

		return $this->root;
	}

	/**
	 * Builds a cache path under a namespace and session.
	 *
	 * @param ImportSessionId   $session_id Session id.
	 * @param string            $cache_namespace Cache namespace.
	 * @param array<int,string> $segments   Additional path segments.
	 * @return string
	 * @throws RuntimeException When the cache is unavailable.
	 */
	public function path_for( ImportSessionId $session_id, $cache_namespace, array $segments ) {
		$path = $this->session_root( $session_id, $cache_namespace );

		foreach ( $segments as $segment ) {
			$segment = trim( str_replace( array( '/', '\\' ), '', (string) $segment ) );

			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				throw new RuntimeException( 'Importer cache path contains an unsafe segment.' );
			}

			$path .= DIRECTORY_SEPARATOR . $segment;
		}

		return $path;
	}

	/**
	 * Ensures the parent directory of a cache file exists.
	 *
	 * @param string $path Cache file path.
	 * @return void
	 * @throws RuntimeException When the directory cannot be created.
	 */
	public function ensure_parent_directory( $path ) {
		$this->ensure_directory( dirname( (string) $path ) );
	}

	/**
	 * Removes all known cache namespaces for a session.
	 *
	 * @param ImportSessionId $session_id Session id.
	 * @return void
	 * @throws RuntimeException When cleanup fails.
	 */
	public function remove_session( ImportSessionId $session_id ) {
		foreach ( array( 'archives', 'github', 'epub', 'browser-uploads' ) as $namespace ) {
			$this->remove_path( $this->session_root( $session_id, $namespace ) );
		}
	}

	/**
	 * Returns metadata suitable for source items and events.
	 *
	 * @param string $cache_namespace Cache namespace.
	 * @param string $path      Cache file path.
	 * @return array<string,string>
	 * @throws RuntimeException When the cache is unavailable.
	 */
	public function metadata_for( $cache_namespace, $path ) {
		return array(
			'cache_backend'   => $this->backend,
			'cache_namespace' => (string) $cache_namespace,
			'cache_root'      => $this->get_root(),
			'cache_path'      => (string) $path,
		);
	}

	/**
	 * Builds a session cache root.
	 *
	 * @param ImportSessionId $session_id Session id.
	 * @param string          $cache_namespace Cache namespace.
	 * @return string
	 * @throws RuntimeException When the cache is unavailable.
	 */
	private function session_root( ImportSessionId $session_id, $cache_namespace ) {
		$this->assert_available();

		$cache_namespace = preg_replace( '/[^a-z0-9_-]+/', '-', strtolower( (string) $cache_namespace ) );
		$cache_namespace = trim( is_string( $cache_namespace ) ? $cache_namespace : '', '-' );

		if ( '' === $cache_namespace ) {
			throw new RuntimeException( 'Importer cache namespace cannot be empty.' );
		}

		return $this->root . DIRECTORY_SEPARATOR . $cache_namespace . DIRECTORY_SEPARATOR . $session_id->to_string();
	}

	/**
	 * Ensures a directory exists.
	 *
	 * @param string $path Directory path.
	 * @return void
	 * @throws RuntimeException When the directory cannot be created.
	 */
	private function ensure_directory( $path ) {
		$path = (string) $path;

		if ( is_dir( $path ) ) {
			return;
		}

		$parent = dirname( $path );

		if ( $parent !== $path && ! is_dir( $parent ) ) {
			$this->ensure_directory( $parent );
		}

		if ( ! mkdir( $path, 0777 ) && ! is_dir( $path ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
			throw new RuntimeException( 'Unable to create importer cache directory: ' . $path );
		}

		$this->allow_directory_traversal( $path );
	}

	/**
	 * Restores owner traversal on newly-created cache directories after restrictive umasks.
	 *
	 * @param string $path Directory path.
	 * @return void
	 * @throws RuntimeException When permissions cannot support traversal.
	 */
	private function allow_directory_traversal( $path ) {
		if ( ! chmod( $path, 0777 ) && ( ! is_readable( $path ) || ! is_writable( $path ) || ! is_executable( $path ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
			throw new RuntimeException( 'Unable to make importer cache directory writable: ' . $path );
		}
	}

	/**
	 * Removes a file or directory tree.
	 *
	 * @param string $path Path to remove.
	 * @return void
	 * @throws RuntimeException When cleanup fails.
	 */
	private function remove_path( $path ) {
		if ( ! file_exists( $path ) && ! is_link( $path ) ) {
			return;
		}

		if ( is_file( $path ) || is_link( $path ) ) {
			if ( ! unlink( $path ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
				throw new RuntimeException( 'Unable to remove importer cache file: ' . $path );
			}

			return;
		}

		foreach ( scandir( $path ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$this->remove_path( rtrim( $path, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . $entry );
		}

		if ( ! rmdir( $path ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
			throw new RuntimeException( 'Unable to remove importer cache directory: ' . $path );
		}
	}

	/**
	 * Throws the deferred unavailable diagnostic if needed.
	 *
	 * @return void
	 * @throws RuntimeException When the cache is unavailable.
	 */
	private function assert_available() {
		if ( '' !== $this->unavailable_reason ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
			throw new RuntimeException( $this->unavailable_reason );
		}
	}
}
