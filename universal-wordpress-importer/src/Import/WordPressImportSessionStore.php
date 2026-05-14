<?php
/**
 * WordPress-backed import session store.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

use InvalidArgumentException;
use RuntimeException;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table names are generated from the WordPress database prefix.

/**
 * Persists import sessions and resume metadata in WordPress custom tables.
 */
final class WordPressImportSessionStore implements ImportSessionStoreInterface {
	/**
	 * WordPress database object.
	 *
	 * @var object
	 */
	private $wpdb;

	/**
	 * Table names keyed by logical table name.
	 *
	 * @var array{sessions:string,idempotency:string,decisions:string,events:string,source_items:string,documents:string,media:string}
	 */
	private $tables;

	/**
	 * Current timestamp provider.
	 *
	 * @var callable
	 */
	private $now_provider;

	/**
	 * Constructor.
	 *
	 * @param object                    $wpdb         WordPress database object.
	 * @param array<string,string>|null $tables       Optional table name override.
	 * @param callable|null             $now_provider Optional unix timestamp provider.
	 * @throws InvalidArgumentException When a database object is not available.
	 */
	public function __construct( $wpdb, array $tables = null, callable $now_provider = null ) {
		if ( ! is_object( $wpdb ) ) {
			throw new InvalidArgumentException( 'A WordPress database object is required for import session storage.' );
		}

		$prefix = isset( $wpdb->prefix ) ? (string) $wpdb->prefix : 'wp_';

		$this->wpdb         = $wpdb;
		$this->tables       = null === $tables ? WordPressImportSessionSchema::get_table_names_for_prefix( $prefix ) : $tables;
		$this->now_provider = null === $now_provider ? array( $this, 'default_now' ) : $now_provider;
	}

	/**
	 * Creates a store from WordPress globals.
	 *
	 * @return self
	 */
	public static function from_globals() {
		global $wpdb;

		return new self( $wpdb );
	}

	/**
	 * Saves a session snapshot.
	 *
	 * @param ImportSession $session Session to save.
	 * @return void
	 */
	public function save( ImportSession $session ) {
		$id         = $session->get_id();
		$now        = $this->mysql_time( $this->now() );
		$checkpoint = $session->get_checkpoint();

		$row = array(
			'source'          => $session->get_source(),
			'dry_run'         => $session->is_dry_run() ? 1 : 0,
			'status'          => $session->get_status(),
			'progress_json'   => $this->encode_json( $session->get_progress()->to_array() ),
			'checkpoint_json' => null === $checkpoint ? null : $this->encode_json( $checkpoint->to_array() ),
			'updated_at'      => $now,
		);

		if ( null === $this->find_raw_session_row( $id ) ) {
			$row['id']         = $id->to_string();
			$row['created_at'] = $now;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Importer state is stored in custom tables.
			$result = $this->wpdb->insert( $this->tables['sessions'], $row );
			$this->assert_database_result( $result, 'insert import session' );
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Importer state is stored in custom tables.
		$result = $this->wpdb->update(
			$this->tables['sessions'],
			$row,
			array( 'id' => $id->to_string() )
		);

		$this->assert_database_result( $result, 'update import session' );
	}

	/**
	 * Loads a session by id.
	 *
	 * @param ImportSessionId $id Session id.
	 * @return ImportSession|null
	 */
	public function find( ImportSessionId $id ) {
		$row = $this->find_raw_session_row( $id );

		if ( null === $row ) {
			return null;
		}

		return $this->session_from_row( $row );
	}

	/**
	 * Lists sessions by status, oldest updated first.
	 *
	 * @param array<int,string> $statuses Statuses to include.
	 * @param int               $limit    Maximum number of sessions.
	 * @return array<int,ImportSession>
	 * @throws InvalidArgumentException When no statuses are provided.
	 */
	public function list_sessions_by_statuses( array $statuses, $limit = 50 ) {
		$statuses = array_values(
			array_unique(
				array_filter(
					array_map(
						function ( $status ) {
							return trim( (string) $status );
						},
						$statuses
					)
				)
			)
		);

		if ( empty( $statuses ) ) {
			throw new InvalidArgumentException( 'At least one import session status is required.' );
		}

		$limit        = max( 1, min( 500, (int) $limit ) );
		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$args         = array_merge( $statuses, array( $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and placeholder count are controlled.
		$query = $this->wpdb->prepare(
			"SELECT * FROM {$this->tables['sessions']} WHERE status IN ({$placeholders}) ORDER BY updated_at ASC LIMIT %d",
			$args
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom importer table.
		$rows = $this->wpdb->get_results( $query, 'ARRAY_A' );

		return array_map( array( $this, 'session_from_row' ), empty( $rows ) ? array() : $rows );
	}

	/**
	 * Lists recent sessions, newest updated first.
	 *
	 * @param int $limit Maximum number of sessions.
	 * @return array<int,ImportSession>
	 */
	public function list_recent_sessions( $limit = 50 ) {
		$limit = max( 1, min( 500, (int) $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated from wpdb prefix.
		$query = $this->wpdb->prepare(
			"SELECT * FROM {$this->tables['sessions']} ORDER BY updated_at DESC LIMIT %d",
			$limit
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom importer table.
		$rows = $this->wpdb->get_results( $query, 'ARRAY_A' );

		return array_map( array( $this, 'session_from_row' ), empty( $rows ) ? array() : $rows );
	}

	/**
	 * Acquires an expiring lock for a session.
	 *
	 * @param ImportSessionId $id          Session id.
	 * @param string          $owner       Worker owner name.
	 * @param int             $ttl_seconds Lock lifetime in seconds.
	 * @return ImportSessionLock|null
	 * @throws InvalidArgumentException When the owner or TTL is invalid.
	 */
	public function acquire_lock( ImportSessionId $id, $owner, $ttl_seconds ) {
		$owner       = trim( (string) $owner );
		$ttl_seconds = (int) $ttl_seconds;

		if ( '' === $owner ) {
			throw new InvalidArgumentException( 'Import lock owner cannot be empty.' );
		}

		if ( $ttl_seconds < 1 ) {
			throw new InvalidArgumentException( 'Import lock TTL must be at least one second.' );
		}

		$now        = $this->mysql_time( $this->now() );
		$expires_at = $this->mysql_time( $this->now() + $ttl_seconds );
		$token      = bin2hex( random_bytes( 16 ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated from wpdb prefix.
		$query = $this->wpdb->prepare(
			"UPDATE {$this->tables['sessions']}
				SET lock_owner = %s, lock_token = %s, locked_until = %s, updated_at = %s
				WHERE id = %s AND (locked_until IS NULL OR locked_until < %s)",
			$owner,
			$token,
			$expires_at,
			$now,
			$id->to_string(),
			$now
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Prepared above; custom table lock update.
		$result = $this->wpdb->query( $query );
		$this->assert_database_result( $result, 'acquire import session lock' );

		if ( 0 === (int) $result ) {
			return null;
		}

		return new ImportSessionLock( $id, $owner, $token, $expires_at );
	}

	/**
	 * Extends a held lock by token and rotates the token.
	 *
	 * @param ImportSessionLock $lock        Current lock token.
	 * @param int               $ttl_seconds Lock lifetime in seconds.
	 * @return ImportSessionLock|null Refreshed lock, or null when the token no longer owns the session.
	 * @throws InvalidArgumentException When the TTL is invalid.
	 */
	public function refresh_lock( ImportSessionLock $lock, $ttl_seconds ) {
		$ttl_seconds = (int) $ttl_seconds;

		if ( $ttl_seconds < 1 ) {
			throw new InvalidArgumentException( 'Import lock TTL must be at least one second.' );
		}

		$now        = $this->mysql_time( $this->now() );
		$expires_at = $this->mysql_time( $this->now() + $ttl_seconds );
		$token      = bin2hex( random_bytes( 16 ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated from wpdb prefix.
		$query = $this->wpdb->prepare(
			"UPDATE {$this->tables['sessions']}
				SET lock_token = %s, locked_until = %s, updated_at = %s
				WHERE id = %s AND lock_token = %s",
			$token,
			$expires_at,
			$now,
			$lock->get_session_id()->to_string(),
			$lock->get_token()
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Prepared above; custom table lock update.
		$result = $this->wpdb->query( $query );
		$this->assert_database_result( $result, 'refresh import session lock' );

		if ( 0 === (int) $result ) {
			return null;
		}

		return new ImportSessionLock( $lock->get_session_id(), $lock->get_owner(), $token, $expires_at );
	}

	/**
	 * Releases a lock by token.
	 *
	 * @param ImportSessionLock $lock Lock to release.
	 * @return bool Whether the lock was released.
	 */
	public function release_lock( ImportSessionLock $lock ) {
		$now = $this->mysql_time( $this->now() );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated from wpdb prefix.
		$query = $this->wpdb->prepare(
			"UPDATE {$this->tables['sessions']}
				SET lock_owner = NULL, lock_token = NULL, locked_until = NULL, updated_at = %s
				WHERE id = %s AND lock_token = %s",
			$now,
			$lock->get_session_id()->to_string(),
			$lock->get_token()
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Prepared above; custom table lock update.
		$result = $this->wpdb->query( $query );
		$this->assert_database_result( $result, 'release import session lock' );

		return 0 < (int) $result;
	}

	/**
	 * Stores or updates an idempotency record.
	 *
	 * @param ImportSessionId         $id     Session id.
	 * @param ImportIdempotencyRecord $record Idempotency record.
	 * @return void
	 */
	public function remember_idempotency_record( ImportSessionId $id, ImportIdempotencyRecord $record ) {
		$now = $this->mysql_time( $this->now() );
		$row = array(
			'resource_type' => $record->get_resource_type(),
			'resource_id'   => $record->get_resource_id(),
			'payload_hash'  => $record->get_payload_hash(),
			'updated_at'    => $now,
		);

		if ( null === $this->find_idempotency_record( $id, $record->get_key() ) ) {
			$row['session_id']      = $id->to_string();
			$row['idempotency_key'] = $record->get_key();
			$row['created_at']      = $now;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Importer state is stored in custom tables.
			$result = $this->wpdb->insert( $this->tables['idempotency'], $row );
			$this->assert_database_result( $result, 'insert import idempotency record' );
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Importer state is stored in custom tables.
		$result = $this->wpdb->update(
			$this->tables['idempotency'],
			$row,
			array(
				'session_id'      => $id->to_string(),
				'idempotency_key' => $record->get_key(),
			)
		);

		$this->assert_database_result( $result, 'update import idempotency record' );
	}

	/**
	 * Finds an idempotency record by key.
	 *
	 * @param ImportSessionId $id  Session id.
	 * @param string          $key Idempotency key.
	 * @return ImportIdempotencyRecord|null
	 * @throws InvalidArgumentException When the idempotency key is invalid.
	 */
	public function find_idempotency_record( ImportSessionId $id, $key ) {
		$key = trim( (string) $key );

		if ( '' === $key ) {
			throw new InvalidArgumentException( 'Idempotency key cannot be empty.' );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated from wpdb prefix.
		$query = $this->wpdb->prepare(
			"SELECT * FROM {$this->tables['idempotency']} WHERE session_id = %s AND idempotency_key = %s",
			$id->to_string(),
			$key
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom importer table.
		$row = $this->wpdb->get_row( $query, 'ARRAY_A' );

		if ( empty( $row ) ) {
			return null;
		}

		return ImportIdempotencyRecord::from_array(
			array(
				'key'           => $row['idempotency_key'],
				'resource_type' => $row['resource_type'],
				'resource_id'   => $row['resource_id'],
				'payload_hash'  => $row['payload_hash'],
			)
		);
	}

	/**
	 * Stores or updates a pending/resolved user decision.
	 *
	 * @param ImportSessionId $id       Session id.
	 * @param ImportDecision  $decision Decision to save.
	 * @return void
	 */
	public function save_decision( ImportSessionId $id, ImportDecision $decision ) {
		$now = $this->mysql_time( $this->now() );
		$row = array(
			'status'       => $decision->get_status(),
			'prompt'       => $decision->get_prompt(),
			'options_json' => $this->encode_json( $decision->get_options() ),
			'answer_json'  => null === $decision->get_answer() ? null : $this->encode_json( $decision->get_answer() ),
			'updated_at'   => $now,
		);

		if ( null === $this->find_decision( $id, $decision->get_key() ) ) {
			$row['session_id']   = $id->to_string();
			$row['decision_key'] = $decision->get_key();
			$row['created_at']   = $now;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Importer state is stored in custom tables.
			$result = $this->wpdb->insert( $this->tables['decisions'], $row );
			$this->assert_database_result( $result, 'insert import decision' );
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Importer state is stored in custom tables.
		$result = $this->wpdb->update(
			$this->tables['decisions'],
			$row,
			array(
				'session_id'   => $id->to_string(),
				'decision_key' => $decision->get_key(),
			)
		);

		$this->assert_database_result( $result, 'update import decision' );
	}

	/**
	 * Resolves a pending user decision.
	 *
	 * @param ImportSessionId     $id           Session id.
	 * @param string              $decision_key Decision key.
	 * @param array<string,mixed> $answer       Structured answer.
	 * @return ImportDecision
	 * @throws RuntimeException When the decision does not exist.
	 */
	public function resolve_decision( ImportSessionId $id, $decision_key, array $answer ) {
		$decision = $this->find_decision( $id, $decision_key );

		if ( null === $decision ) {
			throw new RuntimeException( 'Cannot resolve an unknown import decision.' );
		}

		$resolved = $decision->resolve( $answer );
		$this->save_decision( $id, $resolved );

		return $resolved;
	}

	/**
	 * Finds a decision by key.
	 *
	 * @param ImportSessionId $id           Session id.
	 * @param string          $decision_key Decision key.
	 * @return ImportDecision|null
	 * @throws InvalidArgumentException When the decision key is invalid.
	 */
	public function find_decision( ImportSessionId $id, $decision_key ) {
		$decision_key = trim( (string) $decision_key );

		if ( '' === $decision_key ) {
			throw new InvalidArgumentException( 'Import decision key cannot be empty.' );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated from wpdb prefix.
		$query = $this->wpdb->prepare(
			"SELECT * FROM {$this->tables['decisions']} WHERE session_id = %s AND decision_key = %s",
			$id->to_string(),
			$decision_key
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom importer table.
		$row = $this->wpdb->get_row( $query, 'ARRAY_A' );

		if ( empty( $row ) ) {
			return null;
		}

		return $this->decision_from_row( $row );
	}

	/**
	 * Lists unresolved decisions for a session.
	 *
	 * @param ImportSessionId $id Session id.
	 * @return array<int,ImportDecision>
	 */
	public function list_pending_decisions( ImportSessionId $id ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated from wpdb prefix.
		$query = $this->wpdb->prepare(
			"SELECT * FROM {$this->tables['decisions']} WHERE session_id = %s AND status = %s ORDER BY id ASC",
			$id->to_string(),
			ImportDecision::STATUS_PENDING
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom importer table.
		$rows = $this->wpdb->get_results( $query, 'ARRAY_A' );

		return array_map( array( $this, 'decision_from_row' ), empty( $rows ) ? array() : $rows );
	}

	/**
	 * Lists resolved decisions for a session.
	 *
	 * @param ImportSessionId $id Session id.
	 * @param int             $limit Maximum number of decisions.
	 * @return array<int,ImportDecision>
	 */
	public function list_resolved_decisions( ImportSessionId $id, $limit = 50 ) {
		$limit = max( 1, min( 500, (int) $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated from wpdb prefix.
		$query = $this->wpdb->prepare(
			"SELECT * FROM {$this->tables['decisions']} WHERE session_id = %s AND status = %s ORDER BY id ASC LIMIT %d",
			$id->to_string(),
			ImportDecision::STATUS_RESOLVED,
			$limit
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom importer table.
		$rows = $this->wpdb->get_results( $query, 'ARRAY_A' );

		return array_map( array( $this, 'decision_from_row' ), empty( $rows ) ? array() : $rows );
	}

	/**
	 * Lists resolved decisions with a key prefix that have no matching idempotency record.
	 *
	 * @param ImportSessionId $id                     Session id.
	 * @param string          $decision_key_prefix    Decision key prefix to include.
	 * @param string          $idempotency_key_prefix Idempotency key prefix used for applied decisions.
	 * @param int             $limit                  Maximum number of decisions.
	 * @return array<int,ImportDecision>
	 * @throws InvalidArgumentException When a prefix is empty.
	 */
	public function list_unapplied_resolved_decisions_by_key_prefix( ImportSessionId $id, $decision_key_prefix, $idempotency_key_prefix, $limit = 50 ) {
		$decision_key_prefix    = (string) $decision_key_prefix;
		$idempotency_key_prefix = (string) $idempotency_key_prefix;

		if ( '' === $decision_key_prefix ) {
			throw new InvalidArgumentException( 'Decision key prefix cannot be empty.' );
		}

		if ( '' === $idempotency_key_prefix ) {
			throw new InvalidArgumentException( 'Decision idempotency key prefix cannot be empty.' );
		}

		$limit = max( 1, min( 500, (int) $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are generated from wpdb prefix.
		$query = $this->wpdb->prepare(
			"SELECT decisions.* FROM {$this->tables['decisions']} decisions
				WHERE decisions.session_id = %s
					AND decisions.status = %s
					AND decisions.decision_key LIKE %s
					AND NOT EXISTS (
						SELECT 1 FROM {$this->tables['idempotency']} idempotency
						WHERE idempotency.session_id = decisions.session_id
							AND idempotency.idempotency_key = CONCAT(%s, decisions.decision_key)
					)
				ORDER BY decisions.id ASC
				LIMIT %d",
			$id->to_string(),
			ImportDecision::STATUS_RESOLVED,
			$decision_key_prefix . '%',
			$idempotency_key_prefix,
			$limit
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom importer tables.
		$rows = $this->wpdb->get_results( $query, 'ARRAY_A' );

		return array_map( array( $this, 'decision_from_row' ), empty( $rows ) ? array() : $rows );
	}

	/**
	 * Records an observable progress event.
	 *
	 * @param ImportSessionId     $id    Session id.
	 * @param ImportProgressEvent $event Event to record.
	 * @return ImportProgressEvent Persisted event with created_at set.
	 */
	public function record_event( ImportSessionId $id, ImportProgressEvent $event ) {
		$created_at      = $this->mysql_time( $this->now() );
		$persisted_event = $event->with_created_at( $created_at );

		$row = array(
			'session_id'   => $id->to_string(),
			'level'        => $persisted_event->get_level(),
			'event_type'   => $persisted_event->get_type(),
			'message'      => $persisted_event->get_message(),
			'context_json' => $this->encode_json( $persisted_event->get_context() ),
			'created_at'   => $created_at,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Importer state is stored in custom tables.
		$result = $this->wpdb->insert( $this->tables['events'], $row );
		$this->assert_database_result( $result, 'insert import progress event' );

		return $persisted_event;
	}

	/**
	 * Lists recent progress events for a session, newest first.
	 *
	 * @param ImportSessionId $id    Session id.
	 * @param int             $limit Maximum number of events.
	 * @return array<int,ImportProgressEvent>
	 */
	public function list_events( ImportSessionId $id, $limit = 50 ) {
		$limit = max( 1, min( 500, (int) $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated from wpdb prefix.
		$query = $this->wpdb->prepare(
			"SELECT * FROM {$this->tables['events']} WHERE session_id = %s ORDER BY id DESC LIMIT %d",
			$id->to_string(),
			$limit
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom importer table.
		$rows = $this->wpdb->get_results( $query, 'ARRAY_A' );

		return array_map( array( $this, 'event_from_row' ), empty( $rows ) ? array() : $rows );
	}

	/**
	 * Stores or updates a source tree item.
	 *
	 * @param ImportSourceItem $item Source item.
	 * @return void
	 */
	public function save_source_item( ImportSourceItem $item ) {
		$now = $this->mysql_time( $this->now() );
		$row = array(
			'parent_key'    => $item->get_parent_key(),
			'source_uri'    => $item->get_source_uri(),
			'relative_path' => $item->get_relative_path(),
			'item_type'     => $item->get_type(),
			'status'        => $item->get_status(),
			'metadata_json' => $this->encode_json( $item->get_metadata() ),
			'updated_at'    => $now,
		);

		if ( null === $this->find_source_item( $item->get_session_id(), $item->get_key() ) ) {
			$row['session_id'] = $item->get_session_id()->to_string();
			$row['item_key']   = $item->get_key();
			$row['created_at'] = $now;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Importer source queue is stored in a custom table.
			$result = $this->wpdb->insert( $this->tables['source_items'], $row );
			$this->assert_database_result( $result, 'insert import source item' );
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Importer source queue is stored in a custom table.
		$result = $this->wpdb->update(
			$this->tables['source_items'],
			$row,
			array(
				'session_id' => $item->get_session_id()->to_string(),
				'item_key'   => $item->get_key(),
			)
		);

		$this->assert_database_result( $result, 'update import source item' );
	}

	/**
	 * Finds a source item by session-local key.
	 *
	 * @param ImportSessionId $id       Session id.
	 * @param string          $item_key Item key.
	 * @return ImportSourceItem|null
	 * @throws InvalidArgumentException When the item key is invalid.
	 */
	public function find_source_item( ImportSessionId $id, $item_key ) {
		$item_key = trim( (string) $item_key );

		if ( '' === $item_key ) {
			throw new InvalidArgumentException( 'Source item key cannot be empty.' );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated from wpdb prefix.
		$query = $this->wpdb->prepare(
			"SELECT * FROM {$this->tables['source_items']} WHERE session_id = %s AND item_key = %s",
			$id->to_string(),
			$item_key
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom importer table.
		$row = $this->wpdb->get_row( $query, 'ARRAY_A' );

		return empty( $row ) ? null : $this->source_item_from_row( $row );
	}

	/**
	 * Lists source items by status, oldest updated first.
	 *
	 * @param ImportSessionId   $id       Session id.
	 * @param array<int,string> $statuses Statuses to include.
	 * @param int               $limit    Maximum number of items.
	 * @return array<int,ImportSourceItem>
	 */
	public function list_source_items_by_statuses( ImportSessionId $id, array $statuses, $limit = 50 ) {
		$statuses = $this->normalize_status_list( $statuses, 'At least one source item status is required.' );
		$limit    = max( 1, min( 500, (int) $limit ) );

		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$args         = array_merge(
			array(
				"SELECT * FROM {$this->tables['source_items']} WHERE session_id = %s AND status IN ({$placeholders}) ORDER BY updated_at ASC, id ASC LIMIT %d",
			),
			array( $id->to_string() ),
			$statuses,
			array( $limit )
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and placeholder count are controlled.
		$query = call_user_func_array( array( $this->wpdb, 'prepare' ), $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom importer table.
		$rows = $this->wpdb->get_results( $query, 'ARRAY_A' );

		return array_map( array( $this, 'source_item_from_row' ), empty( $rows ) ? array() : $rows );
	}

	/**
	 * Lists source items by status after an item-key cursor.
	 *
	 * @param ImportSessionId   $id             Session id.
	 * @param array<int,string> $statuses       Statuses to include.
	 * @param string|null       $after_item_key Previous item key, or null for the first page.
	 * @param int               $limit          Maximum number of items.
	 * @return array<int,ImportSourceItem>
	 */
	public function list_source_items_by_statuses_after_item_key( ImportSessionId $id, array $statuses, $after_item_key = null, $limit = 50 ) {
		$statuses       = $this->normalize_status_list( $statuses, 'At least one source item status is required.' );
		$after_item_key = null === $after_item_key ? '' : trim( (string) $after_item_key );
		$limit          = max( 1, min( 500, (int) $limit ) );
		$placeholders   = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		if ( '' === $after_item_key ) {
			$args = array_merge(
				array(
					"SELECT * FROM {$this->tables['source_items']} WHERE session_id = %s AND status IN ({$placeholders}) ORDER BY item_key ASC LIMIT %d",
					$id->to_string(),
				),
				$statuses,
				array( $limit )
			);
		} else {
			$args = array_merge(
				array(
					"SELECT * FROM {$this->tables['source_items']} WHERE session_id = %s AND status IN ({$placeholders}) AND item_key > %s ORDER BY item_key ASC LIMIT %d",
					$id->to_string(),
				),
				$statuses,
				array( $after_item_key, $limit )
			);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and placeholder count are controlled.
		$query = call_user_func_array( array( $this->wpdb, 'prepare' ), $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom importer table.
		$rows = $this->wpdb->get_results( $query, 'ARRAY_A' );

		return array_map( array( $this, 'source_item_from_row' ), empty( $rows ) ? array() : $rows );
	}

	/**
	 * Counts source items by status.
	 *
	 * @param ImportSessionId   $id       Session id.
	 * @param array<int,string> $statuses Statuses to include.
	 * @return int
	 */
	public function count_source_items_by_statuses( ImportSessionId $id, array $statuses ) {
		$statuses     = $this->normalize_status_list( $statuses, 'At least one source item status is required.' );
		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$args         = array_merge(
			array(
				"SELECT COUNT(*) FROM {$this->tables['source_items']} WHERE session_id = %s AND status IN ({$placeholders})",
			),
			array( $id->to_string() ),
			$statuses
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and placeholder count are controlled.
		$query = call_user_func_array( array( $this->wpdb, 'prepare' ), $args );

		if ( method_exists( $this->wpdb, 'get_var' ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom importer table.
			return (int) $this->wpdb->get_var( $query );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom importer table.
		return count( $this->wpdb->get_results( $query, 'ARRAY_A' ) );
	}

	/**
	 * Lists source items for status snapshots, newest updated first.
	 *
	 * @param ImportSessionId $id    Session id.
	 * @param int             $limit Maximum number of items.
	 * @return array<int,ImportSourceItem>
	 */
	public function list_recent_source_items( ImportSessionId $id, $limit = 10 ) {
		$limit = max( 1, min( 100, (int) $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated from wpdb prefix.
		$query = $this->wpdb->prepare(
			"SELECT * FROM {$this->tables['source_items']} WHERE session_id = %s ORDER BY updated_at DESC, id DESC LIMIT %d",
			$id->to_string(),
			$limit
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom importer table.
		$rows = $this->wpdb->get_results( $query, 'ARRAY_A' );

		return array_map( array( $this, 'source_item_from_row' ), empty( $rows ) ? array() : $rows );
	}

	/**
	 * Stores or updates a prepared document.
	 *
	 * @param ImportPreparedDocument $document Prepared document.
	 * @return void
	 */
	public function save_prepared_document( ImportPreparedDocument $document ) {
		$now = $this->mysql_time( $this->now() );
		$row = array(
			'document_format' => $document->get_format(),
			'title'           => $document->get_title(),
			'block_markup'    => $document->get_block_markup(),
			'block_count'     => $document->get_block_count(),
			'content_hash'    => $document->get_content_hash(),
			'metadata_json'   => $this->encode_json( $document->get_metadata() ),
			'updated_at'      => $now,
		);

		if ( null === $this->find_prepared_document( $document->get_session_id(), $document->get_source_item_key() ) ) {
			$row['session_id']      = $document->get_session_id()->to_string();
			$row['source_item_key'] = $document->get_source_item_key();
			$row['created_at']      = $now;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Prepared documents are stored in a custom importer table.
			$result = $this->wpdb->insert( $this->tables['documents'], $row );
			$this->assert_database_result( $result, 'insert prepared import document' );
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Prepared documents are stored in a custom importer table.
		$result = $this->wpdb->update(
			$this->tables['documents'],
			$row,
			array(
				'session_id'      => $document->get_session_id()->to_string(),
				'source_item_key' => $document->get_source_item_key(),
			)
		);

		$this->assert_database_result( $result, 'update prepared import document' );
	}

	/**
	 * Finds a prepared document by source item key.
	 *
	 * @param ImportSessionId $id              Session id.
	 * @param string          $source_item_key Source item key.
	 * @return ImportPreparedDocument|null
	 * @throws InvalidArgumentException When the source item key is invalid.
	 */
	public function find_prepared_document( ImportSessionId $id, $source_item_key ) {
		$source_item_key = trim( (string) $source_item_key );

		if ( '' === $source_item_key ) {
			throw new InvalidArgumentException( 'Prepared document source item key cannot be empty.' );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated from wpdb prefix.
		$query = $this->wpdb->prepare(
			"SELECT * FROM {$this->tables['documents']} WHERE session_id = %s AND source_item_key = %s",
			$id->to_string(),
			$source_item_key
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom importer table.
		$row = $this->wpdb->get_row( $query, 'ARRAY_A' );

		return empty( $row ) ? null : $this->prepared_document_from_row( $row );
	}

	/**
	 * Lists prepared documents, oldest first.
	 *
	 * @param ImportSessionId $id    Session id.
	 * @param int             $limit Maximum number of documents.
	 * @return array<int,ImportPreparedDocument>
	 */
	public function list_prepared_documents( ImportSessionId $id, $limit = 50 ) {
		$limit = max( 1, min( 500, (int) $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated from wpdb prefix.
		$query = $this->wpdb->prepare(
			"SELECT * FROM {$this->tables['documents']} WHERE session_id = %s ORDER BY updated_at ASC, id ASC LIMIT %d",
			$id->to_string(),
			$limit
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom importer table.
		$rows = $this->wpdb->get_results( $query, 'ARRAY_A' );

		return array_map( array( $this, 'prepared_document_from_row' ), empty( $rows ) ? array() : $rows );
	}

	/**
	 * Lists prepared documents after a source item key, ordered by source item key.
	 *
	 * @param ImportSessionId $id                    Session id.
	 * @param string|null     $after_source_item_key Previous source item key, or null for the first page.
	 * @param int             $limit                 Maximum number of documents.
	 * @return array<int,ImportPreparedDocument>
	 */
	public function list_prepared_documents_after_source_item_key( ImportSessionId $id, $after_source_item_key = null, $limit = 50 ) {
		$limit                 = max( 1, min( 500, (int) $limit ) );
		$after_source_item_key = null === $after_source_item_key ? '' : trim( (string) $after_source_item_key );

		if ( '' === $after_source_item_key ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated from wpdb prefix.
			$query = $this->wpdb->prepare(
				"SELECT * FROM {$this->tables['documents']} WHERE session_id = %s ORDER BY source_item_key ASC LIMIT %d",
				$id->to_string(),
				$limit
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated from wpdb prefix.
			$query = $this->wpdb->prepare(
				"SELECT * FROM {$this->tables['documents']} WHERE session_id = %s AND source_item_key > %s ORDER BY source_item_key ASC LIMIT %d",
				$id->to_string(),
				$after_source_item_key,
				$limit
			);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom importer table.
		$rows = $this->wpdb->get_results( $query, 'ARRAY_A' );

		return array_map( array( $this, 'prepared_document_from_row' ), empty( $rows ) ? array() : $rows );
	}
	/**
	 * Lists prepared documents for status snapshots, newest updated first.
	 *
	 * @param ImportSessionId $id    Session id.
	 * @param int             $limit Maximum number of documents.
	 * @return array<int,ImportPreparedDocument>
	 */
	public function list_recent_prepared_documents( ImportSessionId $id, $limit = 5 ) {
		$limit = max( 1, min( 100, (int) $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated from wpdb prefix.
		$query = $this->wpdb->prepare(
			"SELECT * FROM {$this->tables['documents']} WHERE session_id = %s ORDER BY updated_at DESC, id DESC LIMIT %d",
			$id->to_string(),
			$limit
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom importer table.
		$rows = $this->wpdb->get_results( $query, 'ARRAY_A' );

		return array_map( array( $this, 'prepared_document_from_row' ), empty( $rows ) ? array() : $rows );
	}

	/**
	 * Counts prepared documents for a session.
	 *
	 * @param ImportSessionId $id Session id.
	 * @return int
	 */
	public function count_prepared_documents( ImportSessionId $id ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated from wpdb prefix.
		$query = $this->wpdb->prepare(
			"SELECT COUNT(*) FROM {$this->tables['documents']} WHERE session_id = %s",
			$id->to_string()
		);

		if ( method_exists( $this->wpdb, 'get_var' ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom importer table.
			return (int) $this->wpdb->get_var( $query );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom importer table.
		return count( $this->wpdb->get_results( $query, 'ARRAY_A' ) );
	}

	/**
	 * Stores or updates a media reference.
	 *
	 * @param ImportMediaReference $reference Media reference.
	 * @return void
	 */
	public function save_media_reference( ImportMediaReference $reference ) {
		$now = $this->mysql_time( $this->now() );
		$row = array(
			'source_item_key'     => $reference->get_source_item_key(),
			'original_url'        => $reference->get_original_url(),
			'resolved_source_uri' => $reference->get_resolved_source_uri(),
			'media_type'          => $reference->get_media_type(),
			'status'              => $reference->get_status(),
			'metadata_json'       => $this->encode_json( $reference->get_metadata() ),
			'updated_at'          => $now,
		);

		if ( null === $this->find_media_reference( $reference->get_session_id(), $reference->get_key() ) ) {
			$row['session_id']    = $reference->get_session_id()->to_string();
			$row['reference_key'] = $reference->get_key();
			$row['created_at']    = $now;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Media references are stored in a custom importer table.
			$result = $this->wpdb->insert( $this->tables['media'], $row );
			$this->assert_database_result( $result, 'insert import media reference' );
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Media references are stored in a custom importer table.
		$result = $this->wpdb->update(
			$this->tables['media'],
			$row,
			array(
				'session_id'    => $reference->get_session_id()->to_string(),
				'reference_key' => $reference->get_key(),
			)
		);

		$this->assert_database_result( $result, 'update import media reference' );
	}

	/**
	 * Finds a media reference by key.
	 *
	 * @param ImportSessionId $id            Session id.
	 * @param string          $reference_key Reference key.
	 * @return ImportMediaReference|null
	 * @throws InvalidArgumentException When the reference key is invalid.
	 */
	public function find_media_reference( ImportSessionId $id, $reference_key ) {
		$reference_key = trim( (string) $reference_key );

		if ( '' === $reference_key ) {
			throw new InvalidArgumentException( 'Media reference key cannot be empty.' );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated from wpdb prefix.
		$query = $this->wpdb->prepare(
			"SELECT * FROM {$this->tables['media']} WHERE session_id = %s AND reference_key = %s",
			$id->to_string(),
			$reference_key
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom importer table.
		$row = $this->wpdb->get_row( $query, 'ARRAY_A' );

		return empty( $row ) ? null : $this->media_reference_from_row( $row );
	}

	/**
	 * Lists media references by status, oldest updated first.
	 *
	 * @param ImportSessionId   $id       Session id.
	 * @param array<int,string> $statuses Statuses to include.
	 * @param int               $limit    Maximum number of references.
	 * @return array<int,ImportMediaReference>
	 */
	public function list_media_references_by_statuses( ImportSessionId $id, array $statuses, $limit = 50 ) {
		$statuses = $this->normalize_status_list( $statuses, 'At least one media reference status is required.' );
		$limit    = max( 1, min( 500, (int) $limit ) );

		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$args         = array_merge(
			array(
				"SELECT * FROM {$this->tables['media']} WHERE session_id = %s AND status IN ({$placeholders}) ORDER BY updated_at ASC, id ASC LIMIT %d",
			),
			array( $id->to_string() ),
			$statuses,
			array( $limit )
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and placeholder count are controlled.
		$query = call_user_func_array( array( $this->wpdb, 'prepare' ), $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom importer table.
		$rows = $this->wpdb->get_results( $query, 'ARRAY_A' );

		return array_map( array( $this, 'media_reference_from_row' ), empty( $rows ) ? array() : $rows );
	}

	/**
	 * Lists media references by status after a stable reference-key cursor.
	 *
	 * @param ImportSessionId   $id                  Session id.
	 * @param array<int,string> $statuses            Statuses to include.
	 * @param string|null       $after_reference_key Cursor reference key.
	 * @param int               $limit               Maximum number of references.
	 * @return array<int,ImportMediaReference>
	 */
	public function list_media_references_by_statuses_after_reference_key( ImportSessionId $id, array $statuses, $after_reference_key = null, $limit = 50 ) {
		$statuses            = $this->normalize_status_list( $statuses, 'At least one media reference status is required.' );
		$after_reference_key = null === $after_reference_key ? '' : trim( (string) $after_reference_key );
		$limit               = max( 1, min( 500, (int) $limit ) );

		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$args         = array_merge(
			array(
				"SELECT * FROM {$this->tables['media']} WHERE session_id = %s AND status IN ({$placeholders}) AND reference_key > %s ORDER BY reference_key ASC LIMIT %d",
			),
			array( $id->to_string() ),
			$statuses,
			array( $after_reference_key, $limit )
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and placeholder count are controlled.
		$query = call_user_func_array( array( $this->wpdb, 'prepare' ), $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom importer table.
		$rows = $this->wpdb->get_results( $query, 'ARRAY_A' );

		return array_map( array( $this, 'media_reference_from_row' ), empty( $rows ) ? array() : $rows );
	}

	/**
	 * Lists recent media references for status snapshots.
	 *
	 * @param ImportSessionId $id    Session id.
	 * @param int             $limit Maximum number of references.
	 * @return array<int,ImportMediaReference>
	 */
	public function list_recent_media_references( ImportSessionId $id, $limit = 5 ) {
		$limit = max( 1, min( 100, (int) $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated from wpdb prefix.
		$query = $this->wpdb->prepare(
			"SELECT * FROM {$this->tables['media']} WHERE session_id = %s ORDER BY updated_at DESC, id DESC LIMIT %d",
			$id->to_string(),
			$limit
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom importer table.
		$rows = $this->wpdb->get_results( $query, 'ARRAY_A' );

		return array_map( array( $this, 'media_reference_from_row' ), empty( $rows ) ? array() : $rows );
	}

	/**
	 * Counts media references by status.
	 *
	 * @param ImportSessionId   $id       Session id.
	 * @param array<int,string> $statuses Statuses to include.
	 * @return int
	 */
	public function count_media_references_by_statuses( ImportSessionId $id, array $statuses ) {
		$statuses     = $this->normalize_status_list( $statuses, 'At least one media reference status is required.' );
		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$args         = array_merge(
			array(
				"SELECT COUNT(*) FROM {$this->tables['media']} WHERE session_id = %s AND status IN ({$placeholders})",
			),
			array( $id->to_string() ),
			$statuses
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and placeholder count are controlled.
		$query = call_user_func_array( array( $this->wpdb, 'prepare' ), $args );

		if ( method_exists( $this->wpdb, 'get_var' ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom importer table.
			return (int) $this->wpdb->get_var( $query );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom importer table.
		return count( $this->wpdb->get_results( $query, 'ARRAY_A' ) );
	}

	/**
	 * Counts idempotency records by resource type.
	 *
	 * @param ImportSessionId $id            Session id.
	 * @param string          $resource_type Resource type.
	 * @return int
	 * @throws InvalidArgumentException When the resource type is invalid.
	 */
	public function count_idempotency_records_by_resource_type( ImportSessionId $id, $resource_type ) {
		$resource_type = trim( (string) $resource_type );

		if ( '' === $resource_type ) {
			throw new InvalidArgumentException( 'Idempotency resource type cannot be empty.' );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated from wpdb prefix.
		$query = $this->wpdb->prepare(
			"SELECT COUNT(*) FROM {$this->tables['idempotency']} WHERE session_id = %s AND resource_type = %s",
			$id->to_string(),
			$resource_type
		);

		if ( method_exists( $this->wpdb, 'get_var' ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom importer table.
			return (int) $this->wpdb->get_var( $query );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom importer table.
		return count( $this->wpdb->get_results( $query, 'ARRAY_A' ) );
	}

	/**
	 * Loads raw session row data.
	 *
	 * @param ImportSessionId $id Session id.
	 * @return array<string,mixed>|null
	 */
	private function find_raw_session_row( ImportSessionId $id ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated from wpdb prefix.
		$query = $this->wpdb->prepare(
			"SELECT * FROM {$this->tables['sessions']} WHERE id = %s",
			$id->to_string()
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom importer table.
		$row = $this->wpdb->get_row( $query, 'ARRAY_A' );

		return empty( $row ) ? null : $row;
	}

	/**
	 * Recreates a session from a database row.
	 *
	 * @param array<string,mixed> $row Database row.
	 * @return ImportSession
	 */
	private function session_from_row( array $row ) {
		$checkpoint = null;

		if ( isset( $row['checkpoint_json'] ) && null !== $row['checkpoint_json'] && '' !== $row['checkpoint_json'] ) {
			$checkpoint = $this->decode_json( $row['checkpoint_json'], 'checkpoint_json' );
		}

		return ImportSession::from_array(
			array(
				'id'         => $row['id'],
				'source'     => $row['source'],
				'status'     => $row['status'],
				'progress'   => $this->decode_json( $row['progress_json'], 'progress_json' ),
				'checkpoint' => $checkpoint,
				'dry_run'    => ! empty( $row['dry_run'] ),
			)
		);
	}

	/**
	 * Recreates a decision from a database row.
	 *
	 * @param array<string,mixed> $row Database row.
	 * @return ImportDecision
	 */
	private function decision_from_row( array $row ) {
		$answer = null;

		if ( isset( $row['answer_json'] ) && null !== $row['answer_json'] && '' !== $row['answer_json'] ) {
			$answer = $this->decode_json( $row['answer_json'], 'answer_json' );
		}

		return ImportDecision::from_array(
			array(
				'key'     => $row['decision_key'],
				'prompt'  => $row['prompt'],
				'options' => $this->decode_json( $row['options_json'], 'options_json' ),
				'status'  => $row['status'],
				'answer'  => $answer,
			)
		);
	}

	/**
	 * Recreates a progress event from a database row.
	 *
	 * @param array<string,mixed> $row Database row.
	 * @return ImportProgressEvent
	 */
	private function event_from_row( array $row ) {
		return ImportProgressEvent::from_array(
			array(
				'level'      => $row['level'],
				'type'       => $row['event_type'],
				'message'    => $row['message'],
				'context'    => $this->decode_json( $row['context_json'], 'context_json' ),
				'created_at' => $row['created_at'],
			)
		);
	}

	/**
	 * Recreates a source item from a database row.
	 *
	 * @param array<string,mixed> $row Database row.
	 * @return ImportSourceItem
	 */
	private function source_item_from_row( array $row ) {
		return ImportSourceItem::from_array(
			array(
				'session_id'    => $row['session_id'],
				'item_key'      => $row['item_key'],
				'parent_key'    => isset( $row['parent_key'] ) ? $row['parent_key'] : null,
				'source_uri'    => $row['source_uri'],
				'relative_path' => $row['relative_path'],
				'type'          => $row['item_type'],
				'status'        => $row['status'],
				'metadata'      => $this->decode_json( $row['metadata_json'], 'metadata_json' ),
			)
		);
	}

	/**
	 * Recreates a prepared document from a database row.
	 *
	 * @param array<string,mixed> $row Database row.
	 * @return ImportPreparedDocument
	 */
	private function prepared_document_from_row( array $row ) {
		return ImportPreparedDocument::from_array(
			array(
				'session_id'      => $row['session_id'],
				'source_item_key' => $row['source_item_key'],
				'format'          => $row['document_format'],
				'title'           => $row['title'],
				'block_markup'    => $row['block_markup'],
				'block_count'     => $row['block_count'],
				'content_hash'    => $row['content_hash'],
				'metadata'        => $this->decode_json( $row['metadata_json'], 'metadata_json' ),
			)
		);
	}

	/**
	 * Recreates a media reference from a database row.
	 *
	 * @param array<string,mixed> $row Database row.
	 * @return ImportMediaReference
	 */
	private function media_reference_from_row( array $row ) {
		return ImportMediaReference::from_array(
			array(
				'session_id'          => $row['session_id'],
				'reference_key'       => $row['reference_key'],
				'source_item_key'     => $row['source_item_key'],
				'original_url'        => $row['original_url'],
				'resolved_source_uri' => $row['resolved_source_uri'],
				'media_type'          => $row['media_type'],
				'status'              => $row['status'],
				'metadata'            => $this->decode_json( $row['metadata_json'], 'metadata_json' ),
			)
		);
	}

	/**
	 * Normalizes a status list.
	 *
	 * @param array<int,string> $statuses      Statuses.
	 * @param string            $empty_message Empty-list diagnostic.
	 * @return array<int,string>
	 * @throws InvalidArgumentException When no statuses are provided.
	 */
	private function normalize_status_list( array $statuses, $empty_message ) {
		$statuses = array_values(
			array_unique(
				array_filter(
					array_map(
						function ( $status ) {
							return trim( (string) $status );
						},
						$statuses
					)
				)
			)
		);

		if ( empty( $statuses ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
			throw new InvalidArgumentException( $empty_message );
		}

		return $statuses;
	}

	/**
	 * Encodes structured data for storage.
	 *
	 * @param array<string,mixed> $data Data to encode.
	 * @return string
	 * @throws RuntimeException When JSON encoding fails.
	 */
	private function encode_json( array $data ) {
		if ( function_exists( 'wp_json_encode' ) ) {
			$json = wp_json_encode( $data );
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Used in unit tests without WordPress loaded.
			$json = json_encode( $data );
		}

		if ( ! is_string( $json ) ) {
			throw new RuntimeException( 'Unable to encode importer state as JSON.' );
		}

		return $json;
	}

	/**
	 * Decodes structured data from storage.
	 *
	 * @param string $json       JSON string.
	 * @param string $field_name Field name for diagnostics.
	 * @return array<string,mixed>
	 * @throws RuntimeException When stored JSON is invalid.
	 */
	private function decode_json( $json, $field_name ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_decode_json_decode -- Stored importer state is plain JSON.
		$data = json_decode( (string) $json, true );

		if ( ! is_array( $data ) || JSON_ERROR_NONE !== json_last_error() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
			throw new RuntimeException( 'Stored importer JSON is invalid in field ' . $field_name . ': ' . json_last_error_msg() );
		}

		return $data;
	}

	/**
	 * Ensures a database write/query did not fail.
	 *
	 * @param int|false $result    Database result.
	 * @param string    $operation Operation name for diagnostics.
	 * @return void
	 * @throws RuntimeException When a database operation fails.
	 */
	private function assert_database_result( $result, $operation ) {
		if ( false !== $result ) {
			return;
		}

		$last_error = isset( $this->wpdb->last_error ) ? trim( (string) $this->wpdb->last_error ) : '';
		$message    = '' === $last_error ? 'unknown database error' : $last_error;

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
		throw new RuntimeException( 'Failed to ' . $operation . ': ' . $message );
	}

	/**
	 * Returns the current unix timestamp.
	 *
	 * @return int
	 */
	private function now() {
		$provider = $this->now_provider;

		return (int) $provider();
	}

	/**
	 * Default current timestamp provider.
	 *
	 * @return int
	 */
	private function default_now() {
		return time();
	}

	/**
	 * Formats a unix timestamp for UTC database storage.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	private function mysql_time( $timestamp ) {
		return gmdate( 'Y-m-d H:i:s', (int) $timestamp );
	}
}
