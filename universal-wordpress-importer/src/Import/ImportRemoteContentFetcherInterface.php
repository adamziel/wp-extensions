<?php
/**
 * Remote content fetcher contract.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

/**
 * Fetches remote JSON and HTML/text content for importer traversal.
 */
interface ImportRemoteContentFetcherInterface {
	/**
	 * Fetches and decodes a remote JSON document.
	 *
	 * @param string $url Remote URL.
	 * @return array<string,mixed>|array<int,mixed>
	 */
	public function fetch_json( $url );

	/**
	 * Fetches remote text content.
	 *
	 * @param string $url Remote URL.
	 * @return array{body:string,headers:array<string,string>,status_code:int}
	 */
	public function fetch_text( $url );
}
