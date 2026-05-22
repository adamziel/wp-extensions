<?php
/**
 * Git repository fetcher contract.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

/**
 * Fetches a Git repository subtree into importer-managed cache files.
 */
interface GitRepositoryFetcherInterface {
	/**
	 * Fetches repository files into local cache files.
	 *
	 * @param ImportSession        $session         Import session.
	 * @param array<string,mixed>  $repo            Parsed repository data.
	 * @param ImportCacheDirectory $cache_directory Cache directory.
	 * @return array<int,array<string,mixed>>
	 */
	public function fetch( ImportSession $session, array $repo, ImportCacheDirectory $cache_directory );

	/**
	 * Lists repository directories under the requested subtree root.
	 *
	 * Returns an array shaped like:
	 *   array(
	 *     'ref'         => '<resolved branch name>',
	 *     'directories' => array( '<relative repository path>', ... ),
	 *   )
	 *
	 * The returned paths are repository-root-relative and ordered ascendingly.
	 *
	 * @param array<string,mixed>  $repo            Parsed repository data. Must contain owner, name, ref, source_path.
	 * @param ImportCacheDirectory $cache_directory Cache directory used for Git plumbing storage.
	 * @return array{ref:string,directories:array<int,string>}
	 */
	public function list_root_directories( array $repo, ImportCacheDirectory $cache_directory );
}
