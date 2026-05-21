<?php
/**
 * GitHub repository source URL parsing helpers.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

/**
 * Normalizes supported GitHub repository URLs for importer traversal and admin browsing.
 */
final class GitHubRepositorySourceUrl {
	/**
	 * Parses a supported GitHub repository URL.
	 *
	 * @param string $source Source URL.
	 * @return array{owner:string,name:string,ref:string,source_path:string,source_url:string,fallback_candidates?:array<int,array{ref:string,source_path:string}>}|null
	 */
	public static function parse( $source ) {
		$source = trim( (string) $source );

		if ( function_exists( 'wp_parse_url' ) ) {
			$parts = wp_parse_url( $source );
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Unit tests exercise this helper without WordPress loaded.
			$parts = parse_url( $source );
		}

		if ( ! is_array( $parts ) || empty( $parts['host'] ) || 'github.com' !== strtolower( $parts['host'] ) ) {
			return null;
		}

		$path = isset( $parts['path'] ) ? trim( (string) $parts['path'], '/' ) : '';

		if ( '' === $path ) {
			return null;
		}

		$segments = explode( '/', $path );

		if ( count( $segments ) < 2 ) {
			return null;
		}

		$owner = self::normalize_slug( $segments[0] );
		$name  = self::normalize_slug( preg_replace( '/\.git$/', '', $segments[1] ) );

		if ( '' === $owner || '' === $name ) {
			return null;
		}

		$ref         = 'HEAD';
		$source_path = '';
		$fallbacks   = array();

		if ( isset( $segments[2] ) && 'tree' === $segments[2] && isset( $segments[3] ) ) {
			$tree_segments = array_slice( $segments, 3 );
			$ref           = implode( '/', $tree_segments );
			for ( $length = count( $tree_segments ) - 1; $length >= 1; --$length ) {
				$fallback_path = self::normalize_source_path( implode( '/', array_slice( $tree_segments, $length ) ) );
				if ( '' === $fallback_path ) {
					continue;
				}

				$fallbacks[] = array(
					'ref'         => implode( '/', array_slice( $tree_segments, 0, $length ) ),
					'source_path' => $fallback_path,
				);
			}
		} elseif ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
			if ( isset( $query['ref'] ) && '' !== trim( (string) $query['ref'] ) ) {
				$ref = trim( (string) $query['ref'] );
			}
			if ( isset( $query['path'] ) && '' !== trim( (string) $query['path'] ) ) {
				$source_path = self::normalize_source_path( (string) $query['path'] );
			}
		}

		$repo = array(
			'owner'       => $owner,
			'name'        => $name,
			'ref'         => self::normalize_ref( $ref ),
			'source_path' => $source_path,
			'source_url'  => $source,
		);

		if ( ! empty( $fallbacks ) ) {
			$repo['fallback_candidates'] = $fallbacks;
		}

		return $repo;
	}

	/**
	 * Returns GitHub fetch candidates for a parsed URL.
	 *
	 * @param array{owner:string,name:string,ref:string,source_path:string,source_url:string,fallback_candidates?:array<int,array{ref:string,source_path:string}>} $repo Parsed repository data.
	 * @return array<int,array{owner:string,name:string,ref:string,source_path:string,source_url:string,requested_ref?:string}>
	 */
	public static function candidates( array $repo ) {
		$candidates = array(
			array(
				'owner'       => $repo['owner'],
				'name'        => $repo['name'],
				'ref'         => $repo['ref'],
				'source_path' => $repo['source_path'],
				'source_url'  => $repo['source_url'],
			),
		);
		$seen       = array( $repo['ref'] . "\n" . $repo['source_path'] => true );

		foreach ( isset( $repo['fallback_candidates'] ) ? $repo['fallback_candidates'] : array() as $fallback ) {
			$fallback_ref = self::normalize_ref( $fallback['ref'] );
			$source_path  = self::normalize_source_path( $fallback['source_path'] );
			$key          = $fallback_ref . "\n" . $source_path;

			if ( isset( $seen[ $key ] ) || $fallback_ref === $repo['ref'] ) {
				continue;
			}

			$candidates[] = array(
				'owner'         => $repo['owner'],
				'name'          => $repo['name'],
				'ref'           => $fallback_ref,
				'source_path'   => $source_path,
				'source_url'    => $repo['source_url'],
				'requested_ref' => $repo['ref'],
			);
			$seen[ $key ] = true;
		}

		return $candidates;
	}

	/**
	 * Builds the GitHub repository API URL.
	 *
	 * @param array{owner:string,name:string} $repo Repository data.
	 * @return string
	 */
	public static function repository_api_url( array $repo ) {
		return 'https://api.github.com/repos/' . rawurlencode( $repo['owner'] ) . '/' . rawurlencode( $repo['name'] );
	}

	/**
	 * Builds the GitHub REST tree URL.
	 *
	 * @param array{owner:string,name:string,ref:string} $repo Repository data.
	 * @return string
	 */
	public static function tree_api_url( array $repo ) {
		return self::repository_api_url( $repo ) . '/git/trees/' . str_replace( '%2F', '/', rawurlencode( $repo['ref'] ) ) . '?recursive=1';
	}

	/**
	 * Builds a GitHub source URL for a repository path.
	 *
	 * @param array{owner:string,name:string,ref:string} $repo Repository data.
	 * @param string|null                                $path Repository-relative path, or null for root.
	 * @return string
	 */
	public static function source_url( array $repo, $path = null ) {
		$source_path = self::normalize_source_path( null === $path ? '' : (string) $path );
		$url         = 'https://github.com/' . rawurlencode( $repo['owner'] ) . '/' . rawurlencode( $repo['name'] ) . '/tree/' . self::encode_path( $repo['ref'] );

		if ( '' !== $source_path ) {
			$url .= '/' . self::encode_path( $source_path );
		}

		return $url;
	}

	/**
	 * Normalizes a Git ref for API use.
	 *
	 * @param string $ref Ref.
	 * @return string
	 */
	public static function normalize_ref( $ref ) {
		$ref = trim( str_replace( '\\', '/', (string) $ref ), '/' );

		if ( '' === $ref || false !== strpos( $ref, '..' ) || false !== strpos( $ref, "\0" ) ) {
			return 'HEAD';
		}

		return $ref;
	}

	/**
	 * Normalizes an optional path inside a repository tree.
	 *
	 * @param string $path Repository-relative path.
	 * @return string
	 */
	public static function normalize_source_path( $path ) {
		$path = trim( str_replace( '\\', '/', rawurldecode( (string) $path ) ), '/' );

		if ( '' === $path || false !== strpos( $path, "\0" ) ) {
			return '';
		}

		$parts = array();

		foreach ( explode( '/', $path ) as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part ) {
				return '';
			}
			$parts[] = $part;
		}

		return implode( '/', $parts );
	}

	/**
	 * Normalizes owner and repository slugs.
	 *
	 * @param string $slug Slug.
	 * @return string
	 */
	private static function normalize_slug( $slug ) {
		$slug = trim( (string) $slug );

		return preg_match( '/^[A-Za-z0-9_.-]+$/', $slug ) ? $slug : '';
	}

	/**
	 * Encodes one slash-delimited URL path while preserving delimiters.
	 *
	 * @param string $path Slash-delimited path.
	 * @return string
	 */
	private static function encode_path( $path ) {
		$parts = array_map( 'rawurlencode', explode( '/', trim( (string) $path, '/' ) ) );

		return implode( '/', $parts );
	}
}
