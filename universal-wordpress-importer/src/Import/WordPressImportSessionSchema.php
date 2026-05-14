<?php
/**
 * WordPress database schema for import sessions.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

use RuntimeException;

/**
 * Installs and upgrades custom tables used by resumable imports.
 */
final class WordPressImportSessionSchema {
	const SCHEMA_VERSION        = '5';
	const SCHEMA_VERSION_OPTION = 'universal_importer_schema_version';

	const TABLE_SESSIONS     = 'universal_importer_sessions';
	const TABLE_IDEMPOTENCY  = 'universal_importer_idempotency';
	const TABLE_DECISIONS    = 'universal_importer_decisions';
	const TABLE_EVENTS       = 'universal_importer_events';
	const TABLE_SOURCE_ITEMS = 'universal_importer_source_items';
	const TABLE_DOCUMENTS    = 'universal_importer_documents';
	const TABLE_MEDIA        = 'universal_importer_media';

	/**
	 * WordPress database object.
	 *
	 * @var object
	 */
	private $wpdb;

	/**
	 * Constructor.
	 *
	 * @param object $wpdb WordPress database object.
	 * @throws RuntimeException When a database object is not available.
	 */
	public function __construct( $wpdb ) {
		if ( ! is_object( $wpdb ) ) {
			throw new RuntimeException( 'A WordPress database object is required to install importer tables.' );
		}

		$this->wpdb = $wpdb;
	}

	/**
	 * Activation callback.
	 *
	 * @return void
	 */
	public static function activate() {
		self::from_globals()->install();
	}

	/**
	 * Creates a schema installer from WordPress globals.
	 *
	 * @return self
	 */
	public static function from_globals() {
		global $wpdb;

		return new self( $wpdb );
	}

	/**
	 * Installs the schema when the stored version is missing or outdated.
	 *
	 * @return void
	 */
	public function maybe_install() {
		$stored_version = function_exists( 'get_option' ) ? get_option( self::SCHEMA_VERSION_OPTION ) : null;

		if ( self::SCHEMA_VERSION === $stored_version ) {
			return;
		}

		$this->install();
	}

	/**
	 * Installs or upgrades importer tables.
	 *
	 * @return void
	 * @throws RuntimeException When WordPress upgrade functions cannot be loaded.
	 */
	public function install() {
		if ( ! function_exists( 'dbDelta' ) ) {
			if ( ! defined( 'ABSPATH' ) ) {
				throw new RuntimeException( 'WordPress ABSPATH is required to load dbDelta for importer tables.' );
			}

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		foreach ( $this->get_create_table_statements() as $statement ) {
			dbDelta( $statement );
		}

		if ( function_exists( 'update_option' ) ) {
			update_option( self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION, false );
		}
	}

	/**
	 * Returns table names for this installation.
	 *
	 * @return array{sessions:string,idempotency:string,decisions:string,events:string,source_items:string,documents:string,media:string}
	 */
	public function get_table_names() {
		$prefix = isset( $this->wpdb->prefix ) ? (string) $this->wpdb->prefix : 'wp_';

		return self::get_table_names_for_prefix( $prefix );
	}

	/**
	 * Builds table names from a WordPress database prefix.
	 *
	 * @param string $prefix WordPress database prefix.
	 * @return array{sessions:string,idempotency:string,decisions:string,events:string,source_items:string,documents:string,media:string}
	 */
	public static function get_table_names_for_prefix( $prefix ) {
		$prefix = (string) $prefix;

		return array(
			'sessions'     => $prefix . self::TABLE_SESSIONS,
			'idempotency'  => $prefix . self::TABLE_IDEMPOTENCY,
			'decisions'    => $prefix . self::TABLE_DECISIONS,
			'events'       => $prefix . self::TABLE_EVENTS,
			'source_items' => $prefix . self::TABLE_SOURCE_ITEMS,
			'documents'    => $prefix . self::TABLE_DOCUMENTS,
			'media'        => $prefix . self::TABLE_MEDIA,
		);
	}

	/**
	 * Returns dbDelta-compatible CREATE TABLE statements.
	 *
	 * @param string|null $prefix          Optional WordPress table prefix override.
	 * @param string|null $charset_collate Optional charset/collation suffix override.
	 * @return array<int,string>
	 */
	public function get_create_table_statements( $prefix = null, $charset_collate = null ) {
		if ( null === $prefix ) {
			$prefix = isset( $this->wpdb->prefix ) ? (string) $this->wpdb->prefix : 'wp_';
		}

		if ( null === $charset_collate ) {
			$charset_collate = method_exists( $this->wpdb, 'get_charset_collate' )
				? (string) $this->wpdb->get_charset_collate()
				: '';
		}

		return self::build_create_table_statements( (string) $prefix, (string) $charset_collate );
	}

	/**
	 * Builds dbDelta-compatible CREATE TABLE statements.
	 *
	 * @param string $prefix          WordPress table prefix.
	 * @param string $charset_collate Charset/collation suffix.
	 * @return array<int,string>
	 */
	public static function build_create_table_statements( $prefix, $charset_collate ) {
		$tables          = self::get_table_names_for_prefix( $prefix );
		$charset_collate = trim( (string) $charset_collate );
		$suffix          = '' === $charset_collate ? '' : ' ' . $charset_collate;

		return array(
			"CREATE TABLE {$tables['sessions']} (
				id varchar(48) NOT NULL,
				source longtext NOT NULL,
				dry_run tinyint(1) NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL,
				progress_json longtext NOT NULL,
				checkpoint_json longtext NULL,
				lock_owner varchar(191) NULL,
				lock_token varchar(64) NULL,
				locked_until datetime NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY status (status),
				KEY locked_until (locked_until)
			){$suffix};",
			"CREATE TABLE {$tables['idempotency']} (
				session_id varchar(48) NOT NULL,
				idempotency_key varchar(191) NOT NULL,
				resource_type varchar(64) NOT NULL,
				resource_id varchar(191) NOT NULL,
				payload_hash varchar(64) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (session_id,idempotency_key),
				KEY resource_lookup (resource_type,resource_id)
			){$suffix};",
			"CREATE TABLE {$tables['decisions']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				session_id varchar(48) NOT NULL,
				decision_key varchar(191) NOT NULL,
				status varchar(20) NOT NULL,
				prompt longtext NOT NULL,
				options_json longtext NOT NULL,
				answer_json longtext NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY session_decision (session_id,decision_key),
				KEY session_status (session_id,status)
			){$suffix};",
			"CREATE TABLE {$tables['events']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				session_id varchar(48) NOT NULL,
				level varchar(20) NOT NULL,
				event_type varchar(64) NOT NULL,
				message longtext NOT NULL,
				context_json longtext NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY session_event (session_id,id)
			){$suffix};",
			"CREATE TABLE {$tables['source_items']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				session_id varchar(48) NOT NULL,
				item_key varchar(191) NOT NULL,
				parent_key varchar(191) NULL,
				source_uri longtext NOT NULL,
				relative_path longtext NOT NULL,
				item_type varchar(32) NOT NULL,
				status varchar(20) NOT NULL,
				metadata_json longtext NOT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY session_item (session_id,item_key),
				KEY session_status (session_id,status),
				KEY session_parent (session_id,parent_key)
			){$suffix};",
			"CREATE TABLE {$tables['documents']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				session_id varchar(48) NOT NULL,
				source_item_key varchar(191) NOT NULL,
				document_format varchar(32) NOT NULL,
				title text NOT NULL,
				block_markup longtext NOT NULL,
				block_count int(11) unsigned NOT NULL DEFAULT 0,
				content_hash varchar(64) NOT NULL,
				metadata_json longtext NOT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY session_source_item (session_id,source_item_key),
				KEY session_format (session_id,document_format),
				KEY content_hash (content_hash)
			){$suffix};",
			"CREATE TABLE {$tables['media']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				session_id varchar(48) NOT NULL,
				reference_key varchar(191) NOT NULL,
				source_item_key varchar(191) NOT NULL,
				original_url longtext NOT NULL,
				resolved_source_uri longtext NOT NULL,
				media_type varchar(32) NOT NULL,
				status varchar(20) NOT NULL,
				metadata_json longtext NOT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY session_reference (session_id,reference_key),
				KEY session_status (session_id,status),
				KEY session_source_item (session_id,source_item_key)
			){$suffix};",
		);
	}
}
