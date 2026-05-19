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
}
