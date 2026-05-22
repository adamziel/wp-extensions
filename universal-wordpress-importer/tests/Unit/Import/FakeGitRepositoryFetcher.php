<?php
/**
 * Fake Git repository fetcher.
 *
 * @package UniversalImporter\Tests\Unit\Import
 */

namespace UniversalImporter\Tests\Unit\Import;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Unit fixtures use isolated temporary files without WordPress loaded.

use Exception;
use UniversalImporter\Import\GitRepositoryFetcherInterface;
use UniversalImporter\Import\ImportCacheDirectory;
use UniversalImporter\Import\ImportSession;

/**
 * Writes configured repository files into the importer cache for walker tests.
 */
final class FakeGitRepositoryFetcher implements GitRepositoryFetcherInterface {
	/**
	 * Files keyed by repository path.
	 *
	 * @var array<string,string>
	 */
	private $files = array();

	/**
	 * Failure messages keyed by Git ref.
	 *
	 * @var array<string,string>
	 */
	private $ref_failures = array();

	/**
	 * Requested repository candidates.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $requests = array();

	/**
	 * Directory listings keyed by ref + "\n" + source_path.
	 *
	 * Each entry is array{ref:string,directories:array<int,string>}.
	 *
	 * @var array<string,array{ref:string,directories:array<int,string>}>
	 */
	private $directory_listings = array();

	/**
	 * Directory-listing failures keyed by ref + "\n" + source_path.
	 *
	 * @var array<string,string>
	 */
	private $directory_failures = array();

	/**
	 * Directory-listing throwables keyed by ref + "\n" + source_path.
	 *
	 * Used to simulate non-RuntimeException failures (e.g. FilesystemException)
	 * that the admin must still catch as it falls through to the next candidate.
	 *
	 * @var array<string,\Throwable>
	 */
	private $directory_throwables = array();

	/**
	 * Requested directory-listing candidates.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $directory_requests = array();

	/**
	 * Adds a fake repository file.
	 *
	 * @param string $repository_path Repository-relative path.
	 * @param string $contents        File contents.
	 * @return void
	 */
	public function add_file( $repository_path, $contents ) {
		$this->files[ trim( str_replace( '\\', '/', (string) $repository_path ), '/' ) ] = (string) $contents;
	}

	/**
	 * Adds a configured failure for a ref.
	 *
	 * @param string $ref     Git ref.
	 * @param string $message Failure message.
	 * @return void
	 */
	public function fail_ref( $ref, $message ) {
		$this->ref_failures[ (string) $ref ] = (string) $message;
	}

	/**
	 * Fetches repository files into local cache files.
	 *
	 * @param ImportSession        $session         Import session.
	 * @param array<string,mixed>  $repo            Parsed repository data.
	 * @param ImportCacheDirectory $cache_directory Cache directory.
	 * @return array<int,array<string,mixed>>
	 * @throws Exception When configured to fail.
	 */
	public function fetch( ImportSession $session, array $repo, ImportCacheDirectory $cache_directory ) {
		$this->requests[] = $repo;
		$ref              = isset( $repo['ref'] ) ? (string) $repo['ref'] : '';

		if ( isset( $this->ref_failures[ $ref ] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Unit-test diagnostics are not rendered directly.
			throw new Exception( $this->ref_failures[ $ref ] );
		}

		$source_path = isset( $repo['source_path'] ) ? trim( (string) $repo['source_path'], '/' ) : '';
		$prefix      = '' === $source_path ? '' : $source_path . '/';
		$results     = array();

		foreach ( $this->files as $repository_path => $contents ) {
			if ( '' !== $prefix && 0 !== strpos( $repository_path, $prefix ) ) {
				continue;
			}

			$relative_path = '' === $prefix ? $repository_path : substr( $repository_path, strlen( $prefix ) );
			$target        = $cache_directory->path_for(
				$session->get_id(),
				'github',
				array(
					'fake-git-' . hash( 'sha256', $repository_path ),
					basename( $relative_path ),
				)
			);
			$cache_directory->ensure_parent_directory( $target );
			file_put_contents( $target, $contents );

			$results[] = array(
				'repository_path' => $repository_path,
				'relative_path'   => $relative_path,
				'local_path'      => $target,
				'bytes'           => strlen( $contents ),
				'metadata'        => $cache_directory->metadata_for( 'github', $target ),
			);
		}

		return $results;
	}

	/**
	 * Returns requested repository candidates.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_requests() {
		return $this->requests;
	}

	/**
	 * Configures a directory listing response keyed by ref and source path.
	 *
	 * @param string            $ref          Requested ref (e.g. "HEAD" or "trunk").
	 * @param string            $source_path  Requested source path.
	 * @param string            $resolved_ref Resolved branch name returned to the caller.
	 * @param array<int,string> $directories  Repository-relative directory paths.
	 * @return void
	 */
	public function add_directory_listing( $ref, $source_path, $resolved_ref, array $directories ) {
		$this->directory_listings[ (string) $ref . "\n" . (string) $source_path ] = array(
			'ref'         => (string) $resolved_ref,
			'directories' => array_values( array_map( 'strval', $directories ) ),
		);
	}

	/**
	 * Configures a directory-listing failure for a ref + source path.
	 *
	 * @param string $ref         Requested ref.
	 * @param string $source_path Requested source path.
	 * @param string $message     Failure message.
	 * @return void
	 */
	public function fail_directory_listing( $ref, $source_path, $message ) {
		$this->directory_failures[ (string) $ref . "\n" . (string) $source_path ] = (string) $message;
	}

	/**
	 * Configures a directory-listing failure that raises an arbitrary Throwable
	 * for a ref + source path. Use this to simulate non-RuntimeException
	 * failures (e.g. WordPress\Filesystem\FilesystemException) coming out of
	 * the Git fetcher.
	 *
	 * @param string     $ref         Requested ref.
	 * @param string     $source_path Requested source path.
	 * @param \Throwable $throwable   Throwable to raise.
	 * @return void
	 */
	public function throw_directory_listing( $ref, $source_path, \Throwable $throwable ) {
		$this->directory_throwables[ (string) $ref . "\n" . (string) $source_path ] = $throwable;
	}

	/**
	 * Returns directory-listing requests in order.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_directory_requests() {
		return $this->directory_requests;
	}

	/**
	 * Lists repository directories under the requested subtree root.
	 *
	 * @param array<string,mixed>  $repo            Parsed repository data.
	 * @param ImportCacheDirectory $cache_directory Cache directory.
	 * @return array{ref:string,directories:array<int,string>}
	 * @throws \RuntimeException When configured to fail.
	 */
	public function list_root_directories( array $repo, ImportCacheDirectory $cache_directory ) {
		$this->directory_requests[] = $repo;

		$ref         = isset( $repo['ref'] ) ? (string) $repo['ref'] : '';
		$source_path = isset( $repo['source_path'] ) ? (string) $repo['source_path'] : '';
		$key         = $ref . "\n" . $source_path;

		if ( isset( $this->directory_throwables[ $key ] ) ) {
			throw $this->directory_throwables[ $key ];
		}

		if ( isset( $this->directory_failures[ $key ] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Unit-test diagnostics are not rendered directly.
			throw new \RuntimeException( $this->directory_failures[ $key ] );
		}

		if ( ! isset( $this->directory_listings[ $key ] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Unit-test diagnostics are not rendered directly.
			throw new \RuntimeException( 'No fake directory listing for ref=' . $ref . ' source_path=' . $source_path . '.' );
		}

		return $this->directory_listings[ $key ];
	}
}
