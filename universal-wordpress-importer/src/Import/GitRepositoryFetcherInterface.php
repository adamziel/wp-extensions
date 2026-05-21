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
}
