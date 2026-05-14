<?php
/**
 * Tests for import session ids.
 *
 * @package UniversalImporter\Tests\Unit\Import
 */

namespace UniversalImporter\Tests\Unit\Import;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use UniversalImporter\Import\ImportSessionId;

/**
 * Covers import session id validation.
 */
final class ImportSessionIdTest extends TestCase {
	/**
	 * Generated ids use the durable storage format.
	 *
	 * @return void
	 */
	public function test_generate_returns_valid_prefixed_id() {
		$id = ImportSessionId::generate();

		$this->assertSame( 1, preg_match( '/^import_[a-f0-9]{32}$/', $id->to_string() ) );
	}

	/**
	 * Stored ids can be recreated.
	 *
	 * @return void
	 */
	public function test_from_string_accepts_valid_id() {
		$id = ImportSessionId::from_string( 'import_0123456789abcdef0123456789abcdef' );

		$this->assertSame( 'import_0123456789abcdef0123456789abcdef', $id->to_string() );
	}

	/**
	 * Invalid ids are rejected early.
	 *
	 * @return void
	 */
	public function test_from_string_rejects_invalid_id() {
		$this->expectException( InvalidArgumentException::class );

		ImportSessionId::from_string( 'import_not-valid' );
	}
}
