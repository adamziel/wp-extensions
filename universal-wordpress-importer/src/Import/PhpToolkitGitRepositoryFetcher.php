<?php
/**
 * PHP-toolkit Git repository fetcher.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Importer-managed cache files are written outside the uploads API by design.

use RuntimeException;
use WordPress\Filesystem\LocalFilesystem;
use WordPress\Git\GitFilesystem;
use WordPress\Git\GitRepository;
use WordPress\Git\Model\Commit;
use WordPress\HttpClient\Client;

/**
 * Fetches GitHub repository subtrees through php-toolkit Git plumbing.
 */
final class PhpToolkitGitRepositoryFetcher implements GitRepositoryFetcherInterface {
	const MAX_FILES = 1000;

	/**
	 * Fetches repository files into local cache files.
	 *
	 * @param ImportSession        $session         Import session.
	 * @param array<string,mixed>  $repo            Parsed repository data.
	 * @param ImportCacheDirectory $cache_directory Cache directory.
	 * @return array<int,array<string,mixed>>
	 * @throws RuntimeException When the Git fetch fails or the repository is not supported by the Git plumbing path.
	 */
	public function fetch( ImportSession $session, array $repo, ImportCacheDirectory $cache_directory ) {
		$this->assert_toolkit_available();

		$branch = $this->branch_name( isset( $repo['ref'] ) ? (string) $repo['ref'] : '' );
		if ( '' === $branch ) {
			throw new RuntimeException( 'php-toolkit Git traversal requires an explicit branch name.' );
		}

		$repository_root = $cache_directory->path_for(
			$session->get_id(),
			'github-git',
			array(
				hash(
					'sha256',
					strtolower( (string) $repo['owner'] ) . '/' . strtolower( (string) $repo['name'] ) . "\n" . $branch . "\n" . (string) $repo['source_path']
				),
			)
		);

		$git_repository = new GitRepository( LocalFilesystem::create( $repository_root ), array( 'default_branch' => $branch ) );
		$branch_ref     = 'refs/heads/' . $branch;
		$remote_url     = 'https://github.com/' . rawurlencode( (string) $repo['owner'] ) . '/' . rawurlencode( (string) $repo['name'] ) . '.git';
		$http_client    = $this->http_client();

		$git_repository->add_remote( 'origin', $remote_url );
		$git_repository->set_branch_tip( $branch_ref, Commit::NULL_HASH );
		$git_repository->get_remote_client( 'origin', array( 'http_client' => $http_client ) )->pull(
			$branch_ref,
			array(
				'force'   => true,
				'path'    => (string) $repo['source_path'],
				'shallow' => true,
			)
		);

		$filesystem = GitFilesystem::create( $git_repository );
		$root       = '' === (string) $repo['source_path'] ? '/' : '/' . trim( (string) $repo['source_path'], '/' );

		if ( ! $filesystem->is_dir( $root ) && ! $filesystem->is_file( $root ) ) {
			throw new RuntimeException( 'php-toolkit Git traversal did not find the requested repository path.' );
		}

		$files = array();
		$this->collect_files( $filesystem, $root, $repo, $session, $cache_directory, $files );
		$this->expand_files_with_markdown_links( $git_repository, $filesystem, $branch_ref, $repo, $session, $cache_directory, $files, $http_client );

		if ( empty( $files ) ) {
			throw new RuntimeException( 'php-toolkit Git traversal did not find importable files.' );
		}

		return $files;
	}

	/**
	 * Verifies php-toolkit Git classes are installed.
	 *
	 * @return void
	 * @throws RuntimeException When required classes are unavailable.
	 */
	private function assert_toolkit_available() {
		if (
			! class_exists( GitRepository::class )
			|| ! class_exists( GitFilesystem::class )
			|| ! class_exists( LocalFilesystem::class )
			|| ! class_exists( Client::class )
		) {
			throw new RuntimeException( 'wp-php-toolkit/git is not available.' );
		}
	}

	/**
	 * Builds the HTTP client used for Git upload-pack requests.
	 *
	 * @return object HTTP client.
	 */
	private function http_client() {
		if ( function_exists( 'wp_remote_request' ) ) {
			return new WordPressGitHttpClient();
		}

		return new Client(
			array(
				'transport'  => 'sockets',
				'timeout_ms' => 300000,
			)
		);
	}

	/**
	 * Returns a branch name supported by php-toolkit GitRemote.
	 *
	 * @param string $ref Git ref.
	 * @return string
	 */
	private function branch_name( $ref ) {
		$ref = trim( str_replace( '\\', '/', (string) $ref ), '/' );

		if ( '' === $ref || 'HEAD' === strtoupper( $ref ) ) {
			return '';
		}

		if ( 0 === strpos( $ref, 'refs/heads/' ) ) {
			$ref = substr( $ref, strlen( 'refs/heads/' ) );
		}

		if ( false !== strpos( $ref, '..' ) || false !== strpos( $ref, "\0" ) || preg_match( '/^[0-9a-f]{40}$/i', $ref ) ) {
			return '';
		}

		return $ref;
	}

	/**
	 * Collects files recursively from a GitFilesystem root.
	 *
	 * @param object                         $filesystem      GitFilesystem chroot layer.
	 * @param string                         $path            Current repository path.
	 * @param array<string,mixed>            $repo            Repository data.
	 * @param ImportSession                  $session         Import session.
	 * @param ImportCacheDirectory           $cache_directory Cache directory.
	 * @param array<int,array<string,mixed>> $files           Files collected so far.
	 * @return void
	 * @throws RuntimeException When the subtree is too large or a cache file cannot be written.
	 */
	private function collect_files( $filesystem, $path, array $repo, ImportSession $session, ImportCacheDirectory $cache_directory, array &$files ) {
		if ( self::MAX_FILES <= count( $files ) ) {
			throw new RuntimeException( 'php-toolkit Git traversal exceeded the importer repository file limit.' );
		}

		if ( $filesystem->is_file( $path ) ) {
			$repository_path = trim( (string) $path, '/' );
			$relative_path   = $this->relative_path( (string) $repo['source_path'], $repository_path );
			$content         = $filesystem->get_contents( $path );
			$target          = $cache_directory->path_for(
				$session->get_id(),
				'github',
				array(
					'git-' . hash( 'sha256', strtolower( (string) $repo['owner'] ) . '/' . strtolower( (string) $repo['name'] ) . "\n" . (string) $repo['ref'] . "\n" . $repository_path ),
					basename( $relative_path ),
				)
			);

			$cache_directory->ensure_parent_directory( $target );
			if ( false === file_put_contents( $target, $content ) ) {
				throw new RuntimeException( 'php-toolkit Git blob cache file could not be written.' );
			}

			$files[] = array(
				'repository_path' => $repository_path,
				'relative_path'   => $relative_path,
				'local_path'      => $target,
				'bytes'           => strlen( $content ),
				'metadata'        => $cache_directory->metadata_for( 'github', $target ),
			);
			return;
		}

		$children = $filesystem->ls( $path );
		sort( $children, SORT_STRING );

		foreach ( $children as $child ) {
			$child_path = '/' === $path ? '/' . $child : rtrim( $path, '/' ) . '/' . $child;
			$this->collect_files( $filesystem, $child_path, $repo, $session, $cache_directory, $files );
		}
	}

	/**
	 * Pulls additional local Markdown documents linked from fetched Markdown files.
	 *
	 * @param GitRepository                  $git_repository Git repository.
	 * @param object                         $filesystem Git filesystem.
	 * @param string                         $branch_ref Branch ref.
	 * @param array<string,mixed>            $repo Repository data.
	 * @param ImportSession                  $session Import session.
	 * @param ImportCacheDirectory           $cache_directory Cache directory.
	 * @param array<int,array<string,mixed>> $files Files collected so far.
	 * @param object                         $http_client Git HTTP client.
	 * @return void
	 */
	private function expand_files_with_markdown_links( GitRepository $git_repository, $filesystem, $branch_ref, array $repo, ImportSession $session, ImportCacheDirectory $cache_directory, array &$files, $http_client ) {
		$indexed = array();
		$queue   = array();

		foreach ( $files as $file ) {
			if ( empty( $file['repository_path'] ) ) {
				continue;
			}
			$path             = $this->normalize_repository_path( (string) $file['repository_path'] );
			$indexed[ $path ] = true;
			$queue[]          = $path;
		}

		$indexed_count = count( $indexed );
		while ( ! empty( $queue ) && $indexed_count < self::MAX_FILES ) {
			$path = array_shift( $queue );
			if ( ! $this->is_markdown_repository_path( $path ) ) {
				continue;
			}

			$local_file = $this->file_descriptor_for_path( $files, $path );
			if ( null === $local_file || empty( $local_file['local_path'] ) || ! is_file( (string) $local_file['local_path'] ) ) {
				continue;
			}

			$content = file_get_contents( (string) $local_file['local_path'] );
			if ( false === $content ) {
				continue;
			}

			foreach ( $this->local_markdown_paths_from_markdown( $content, $path ) as $target_path ) {
				if ( isset( $indexed[ $target_path ] ) || $indexed_count >= self::MAX_FILES ) {
					continue;
				}

				try {
					$git_repository->get_remote_client( 'origin', array( 'http_client' => $http_client ) )->pull(
						$branch_ref,
						array(
							'force'   => true,
							'path'    => $target_path,
							'shallow' => true,
						)
					);

					if ( ! $filesystem->is_file( '/' . $target_path ) ) {
						continue;
					}

					$this->collect_files( $filesystem, '/' . $target_path, $repo, $session, $cache_directory, $files );
				} catch ( RuntimeException $exception ) {
					continue;
				} catch ( \Throwable $exception ) {
					continue;
				}

				$indexed[ $target_path ] = true;
				$indexed_count           = count( $indexed );
				$queue[]                 = $target_path;
			}
		}
	}

	/**
	 * Finds one file descriptor by repository path.
	 *
	 * @param array<int,array<string,mixed>> $files Files.
	 * @param string                         $path Repository path.
	 * @return array<string,mixed>|null
	 */
	private function file_descriptor_for_path( array $files, $path ) {
		foreach ( $files as $file ) {
			if ( isset( $file['repository_path'] ) && $path === $this->normalize_repository_path( (string) $file['repository_path'] ) ) {
				return $file;
			}
		}

		return null;
	}

	/**
	 * Extracts local Markdown target paths from a Markdown document.
	 *
	 * @param string $content Markdown content.
	 * @param string $source_path Repository-relative source file path.
	 * @return array<int,string>
	 */
	private function local_markdown_paths_from_markdown( $content, $source_path ) {
		$paths  = array();
		$length = strlen( (string) $content );

		for ( $index = 0; $index < $length; ++$index ) {
			if ( '[' !== $content[ $index ] ) {
				continue;
			}

			$label_end = $this->find_markdown_closing_delimiter( $content, $index + 1, ']' );
			if ( null === $label_end || ! isset( $content[ $label_end + 1 ] ) || '(' !== $content[ $label_end + 1 ] ) {
				continue;
			}

			$href_start = $label_end + 2;
			$href_end   = $this->find_markdown_closing_delimiter( $content, $href_start, ')' );
			if ( null === $href_end ) {
				continue;
			}

			$href = trim( substr( $content, $href_start, $href_end - $href_start ) );
			if ( false !== strpos( $href, ' ' ) ) {
				$href = substr( $href, 0, strpos( $href, ' ' ) );
			}
			$path = $this->local_markdown_repository_path( $href, $source_path );
			if ( null !== $path ) {
				$paths[ $path ] = $path;
			}
			$index = $href_end;
		}

		return array_values( $paths );
	}

	/**
	 * Finds an unescaped Markdown closing delimiter.
	 *
	 * @param string $content Content.
	 * @param int    $offset Offset.
	 * @param string $delimiter Delimiter.
	 * @return int|null
	 */
	private function find_markdown_closing_delimiter( $content, $offset, $delimiter ) {
		$length = strlen( (string) $content );
		for ( $index = (int) $offset; $index < $length; ++$index ) {
			if ( '\\' === $content[ $index ] ) {
				++$index;
				continue;
			}
			if ( $delimiter === $content[ $index ] ) {
				return $index;
			}
		}

		return null;
	}

	/**
	 * Converts a Markdown href into a repository path when it targets a local Markdown file.
	 *
	 * @param string $href Href.
	 * @param string $source_path Repository-relative source file path.
	 * @return string|null
	 */
	private function local_markdown_repository_path( $href, $source_path ) {
		$href = trim( html_entity_decode( (string) $href, ENT_QUOTES, 'UTF-8' ), '<>' );

		if ( '' === $href || '#' === substr( $href, 0, 1 ) || '//' === substr( $href, 0, 2 ) ) {
			return null;
		}

		if ( preg_match( '/^[a-z][a-z0-9+.-]*:/i', $href ) ) {
			return null;
		}

		$parts = parse_url( $href ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- WordPress is not loaded in importer unit tests.
		if ( false === $parts || empty( $parts['path'] ) ) {
			return null;
		}

		$path = (string) $parts['path'];
		if ( ! $this->is_markdown_repository_path( $path ) ) {
			return null;
		}

		return '/' === substr( $path, 0, 1 )
			? $this->normalize_repository_path( ltrim( $path, '/' ) )
			: $this->normalize_repository_path( dirname( $source_path ) . '/' . $path );
	}

	/**
	 * Normalizes a repository path.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private function normalize_repository_path( $path ) {
		$segments = array();
		foreach ( explode( '/', str_replace( '\\', '/', (string) $path ) ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				array_pop( $segments );
				continue;
			}
			$segments[] = $segment;
		}

		return implode( '/', $segments );
	}

	/**
	 * Returns whether a repository path points to Markdown.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private function is_markdown_repository_path( $path ) {
		$extension = strtolower( pathinfo( (string) $path, PATHINFO_EXTENSION ) );

		return in_array( $extension, array( 'md', 'markdown', 'mdown' ), true );
	}

	/**
	 * Returns an import-relative path for a repository path.
	 *
	 * @param string $source_path     Requested repository subtree.
	 * @param string $repository_path Repository path.
	 * @return string
	 */
	private function relative_path( $source_path, $repository_path ) {
		$source_path     = trim( (string) $source_path, '/' );
		$repository_path = trim( (string) $repository_path, '/' );

		if ( '' === $source_path ) {
			return $repository_path;
		}

		$prefix = $source_path . '/';

		return 0 === strpos( $repository_path, $prefix ) ? substr( $repository_path, strlen( $prefix ) ) : basename( $repository_path );
	}
}
