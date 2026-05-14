<?php
/**
 * Remote archive fetcher contract.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

/**
 * Downloads remote archives into importer-managed cache files.
 */
interface ImportRemoteArchiveFetcherInterface {
	/**
	 * Downloads a remote archive URL to a local target path.
	 *
	 * @param string $url         Archive URL.
	 * @param string $target_path Absolute local target path.
	 * @return array<string,mixed> Fetch metadata.
	 */
	public function fetch( $url, $target_path );
}
