<?php
/**
 * Tests for WordPress import session schema generation.
 *
 * @package UniversalImporter\Tests\Unit\Import
 */

namespace UniversalImporter\Tests\Unit\Import;

use PHPUnit\Framework\TestCase;
use UniversalImporter\Import\WordPressImportSessionSchema;

/**
 * Covers custom table schema generation.
 */
final class WordPressImportSessionSchemaTest extends TestCase {
	/**
	 * Table names are generated from the WordPress prefix.
	 *
	 * @return void
	 */
	public function test_table_names_use_database_prefix() {
		$this->assertSame(
			array(
				'sessions'     => 'wp_universal_importer_sessions',
				'idempotency'  => 'wp_universal_importer_idempotency',
				'decisions'    => 'wp_universal_importer_decisions',
				'events'       => 'wp_universal_importer_events',
				'source_items' => 'wp_universal_importer_source_items',
				'documents'    => 'wp_universal_importer_documents',
				'media'        => 'wp_universal_importer_media',
			),
			WordPressImportSessionSchema::get_table_names_for_prefix( 'wp_' )
		);
	}

	/**
	 * Generated schema includes all durable importer tables.
	 *
	 * @return void
	 */
	public function test_schema_contains_sessions_locks_idempotency_decisions_events_source_items_documents_and_media() {
		$sql = implode(
			"\n",
			WordPressImportSessionSchema::build_create_table_statements(
				'wp_',
				'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
			)
		);

		$this->assertStringContainsString( 'CREATE TABLE wp_universal_importer_sessions', $sql );
		$this->assertStringContainsString( 'dry_run tinyint(1) NOT NULL DEFAULT 0', $sql );
		$this->assertStringContainsString( 'lock_owner varchar(191) NULL', $sql );
		$this->assertStringContainsString( 'locked_until datetime NULL', $sql );
		$this->assertStringContainsString( 'CREATE TABLE wp_universal_importer_idempotency', $sql );
		$this->assertStringContainsString( 'PRIMARY KEY  (session_id,idempotency_key)', $sql );
		$this->assertStringContainsString( 'CREATE TABLE wp_universal_importer_decisions', $sql );
		$this->assertStringContainsString( 'UNIQUE KEY session_decision (session_id,decision_key)', $sql );
		$this->assertStringContainsString( 'CREATE TABLE wp_universal_importer_events', $sql );
		$this->assertStringContainsString( 'KEY session_event (session_id,id)', $sql );
		$this->assertStringContainsString( 'CREATE TABLE wp_universal_importer_source_items', $sql );
		$this->assertStringContainsString( 'UNIQUE KEY session_item (session_id,item_key)', $sql );
		$this->assertStringContainsString( 'KEY session_status (session_id,status)', $sql );
		$this->assertStringContainsString( 'CREATE TABLE wp_universal_importer_documents', $sql );
		$this->assertStringContainsString( 'UNIQUE KEY session_source_item (session_id,source_item_key)', $sql );
		$this->assertStringContainsString( 'block_markup longtext NOT NULL', $sql );
		$this->assertStringContainsString( 'CREATE TABLE wp_universal_importer_media', $sql );
		$this->assertStringContainsString( 'UNIQUE KEY session_reference (session_id,reference_key)', $sql );
		$this->assertStringContainsString( 'resolved_source_uri longtext NOT NULL', $sql );
	}
}
