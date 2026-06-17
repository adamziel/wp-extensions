<?php
/**
 * PHPStan-only declarations for php-toolkit HTTP client docblock symbols.
 *
 * @package UniversalImporter
 */

namespace WordPress\HttpClient;

/**
 * Older php-toolkit HTTP client metadata refers to request body streams by this
 * local name; runtime instances expose the same method through ByteReadStream.
 */
class WP_Byte_Reader {
	/**
	 * Consumes all remaining bytes from the upload stream.
	 *
	 * @return string
	 */
	public function consume_all() {}
}
