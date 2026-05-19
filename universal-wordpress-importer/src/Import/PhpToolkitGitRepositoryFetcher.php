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

		$git_repository->add_remote( 'origin', $remote_url );
		$git_repository->set_branch_tip( $branch_ref, Commit::NULL_HASH );
		$git_repository->get_remote_client( 'origin' )->pull(
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
		) {
			throw new RuntimeException( 'wp-php-toolkit/git is not available.' );
		}
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
