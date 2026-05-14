<?php
/**
 * Fake WordPress database object for import store tests.
 *
 * @package UniversalImporter\Tests\Unit\Import
 */

namespace UniversalImporter\Tests\Unit\Import;

/**
 * Minimal in-memory subset of wpdb used by WordPressImportSessionStore.
 */
final class FakeWpdb {
	/**
	 * WordPress table prefix.
	 *
	 * @var string
	 */
	public $prefix = 'wp_';

	/**
	 * Last database error.
	 *
	 * @var string
	 */
	public $last_error = '';

	/**
	 * Rows keyed by table name.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	private $tables = array();

	/**
	 * Update calls keyed by table name.
	 *
	 * @var array<string,array<int,array{data:array<string,mixed>,where:array<string,mixed>}>>
	 */
	private $updates = array();

	/**
	 * Auto-increment counters keyed by table name.
	 *
	 * @var array<string,int>
	 */
	private $auto_increment = array();

	/**
	 * Optional file path for write-through persistence across child processes.
	 *
	 * @var string|null
	 */
	private $persistence_path;

	/**
	 * Loads a fake database from a persisted snapshot file.
	 *
	 * @param string $path Snapshot path.
	 * @return self
	 */
	public static function from_persisted_file( $path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions -- Test-only snapshot reads a local fake database file.
		$contents = is_file( $path ) ? file_get_contents( $path ) : false;

		if ( false === $contents ) {
			$instance = new self();
			$instance->persist_to_file( $path );

			return $instance;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Test-only snapshot is written by this fake database in the same test process.
		$instance = unserialize( $contents, array( 'allowed_classes' => array( self::class ) ) );

		if ( ! $instance instanceof self ) {
			$instance = new self();
		}

		$instance->persist_to_file( $path );

		return $instance;
	}

	/**
	 * Enables write-through persistence to a snapshot file.
	 *
	 * @param string $path Snapshot path.
	 * @return void
	 */
	public function persist_to_file( $path ) {
		$this->persistence_path = (string) $path;
		$this->persist();
	}

	/**
	 * Returns a fake charset/collation suffix.
	 *
	 * @return string
	 */
	public function get_charset_collate() {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}

	/**
	 * Inserts a row into a fake table.
	 *
	 * @param string              $table Table name.
	 * @param array<string,mixed> $data  Row data.
	 * @return int|false
	 */
	public function insert( $table, array $data ) {
		$this->ensure_table( $table );

		if ( $this->is_auto_increment_table( $table ) && ! isset( $data['id'] ) ) {
			$data['id'] = $this->auto_increment[ $table ];
			++$this->auto_increment[ $table ];
		}

		$this->tables[ $table ][] = $data;
		$this->persist();

		return 1;
	}

	/**
	 * Updates rows in a fake table.
	 *
	 * @param string              $table Table name.
	 * @param array<string,mixed> $data  Updated row data.
	 * @param array<string,mixed> $where Equality conditions.
	 * @return int|false
	 */
	public function update( $table, array $data, array $where ) {
		$this->ensure_table( $table );
		if ( ! isset( $this->updates[ $table ] ) ) {
			$this->updates[ $table ] = array();
		}
		$this->updates[ $table ][] = array(
			'data'  => $data,
			'where' => $where,
		);

		$updated = 0;

		foreach ( $this->tables[ $table ] as &$row ) {
			if ( ! $this->row_matches( $row, $where ) ) {
				continue;
			}

			$row = array_merge( $row, $data );
			++$updated;
		}

		$this->persist();

		return $updated;
	}

	/**
	 * Captures a prepared query and its arguments.
	 *
	 * @param string $query Query with placeholders.
	 * @return array{query:string,args:array<int,mixed>}
	 */
	public function prepare( $query ) {
		$args = func_get_args();
		array_shift( $args );

		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		return array(
			'query' => $query,
			'args'  => $args,
		);
	}

	/**
	 * Runs a fake write query.
	 *
	 * @param array{query:string,args:array<int,mixed>} $prepared Prepared query payload.
	 * @return int|false
	 */
	public function query( array $prepared ) {
		if ( false !== strpos( $prepared['query'], 'SET lock_owner = %s' ) ) {
			$result = $this->acquire_lock( $prepared['query'], $prepared['args'] );
			$this->persist();

			return $result;
		}

		if ( false !== strpos( $prepared['query'], 'SET lock_token = %s' ) ) {
			$result = $this->refresh_lock( $prepared['query'], $prepared['args'] );
			$this->persist();

			return $result;
		}

		if ( false !== strpos( $prepared['query'], 'SET lock_owner = NULL' ) ) {
			$result = $this->release_lock( $prepared['query'], $prepared['args'] );
			$this->persist();

			return $result;
		}

		$this->last_error = 'Unhandled fake query.';

		return false;
	}

	/**
	 * Returns the first matching fake row.
	 *
	 * @param array{query:string,args:array<int,mixed>} $prepared Prepared query payload.
	 * @param string|null                               $output   Output mode.
	 * @return array<string,mixed>|null
	 */
	public function get_row( array $prepared, $output = null ) {
		unset( $output );

		$rows = $this->get_results( $prepared );

		return isset( $rows[0] ) ? $rows[0] : null;
	}

	/**
	 * Returns a scalar fake query result.
	 *
	 * @param array{query:string,args:array<int,mixed>} $prepared Prepared query payload.
	 * @return mixed
	 */
	public function get_var( array $prepared ) {
		if ( false !== strpos( $prepared['query'], 'SELECT COUNT(*)' ) ) {
			return count( $this->get_results( $prepared ) );
		}

		$row = $this->get_row( $prepared );

		return empty( $row ) ? null : reset( $row );
	}

	/**
	 * Returns matching fake rows.
	 *
	 * @param array{query:string,args:array<int,mixed>} $prepared Prepared query payload.
	 * @param string|null                               $output   Output mode.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_results( array $prepared, $output = null ) {
		unset( $output );

		$table = $this->extract_table_name( $prepared['query'] );
		$this->ensure_table( $table );

		$rows = $this->tables[ $table ];
		$args = $prepared['args'];

		if ( false !== strpos( $prepared['query'], 'WHERE id = %s' ) ) {
			$rows = $this->filter_rows( $rows, array( 'id' => $args[0] ) );
		} elseif ( false !== strpos( $prepared['query'], 'WHERE session_id = %s AND source_item_key = %s' ) ) {
			$rows = $this->filter_rows(
				$rows,
				array(
					'session_id'      => $args[0],
					'source_item_key' => $args[1],
				)
			);
		} elseif ( false !== strpos( $prepared['query'], 'WHERE session_id = %s AND reference_key = %s' ) ) {
			$rows = $this->filter_rows(
				$rows,
				array(
					'session_id'    => $args[0],
					'reference_key' => $args[1],
				)
			);
		} elseif ( false !== strpos( $prepared['query'], 'WHERE session_id = %s AND item_key = %s' ) ) {
			$rows = $this->filter_rows(
				$rows,
				array(
					'session_id' => $args[0],
					'item_key'   => $args[1],
				)
			);
		} elseif ( false !== strpos( $prepared['query'], 'WHERE session_id = %s AND resource_type = %s' ) ) {
			$rows = $this->filter_rows(
				$rows,
				array(
					'session_id'    => $args[0],
					'resource_type' => $args[1],
				)
			);
		} elseif ( false !== strpos( $prepared['query'], 'WHERE session_id = %s AND status IN' ) && false !== strpos( $prepared['query'], 'AND reference_key > %s ORDER BY reference_key ASC LIMIT %d' ) ) {
			$limit               = (int) array_pop( $args );
			$after_reference_key = (string) array_pop( $args );
			$session_id          = array_shift( $args );
			$statuses            = $args;
			$rows                = $this->filter_rows_after_reference_key( $rows, $session_id, $statuses, $after_reference_key );
			usort( $rows, array( $this, 'compare_rows_by_reference_key_ascending' ) );
			$rows = array_slice( $rows, 0, $limit );
		} elseif ( false !== strpos( $prepared['query'], 'WHERE session_id = %s AND status IN' ) && false !== strpos( $prepared['query'], 'AND item_key > %s ORDER BY item_key ASC LIMIT %d' ) ) {
			$limit          = (int) array_pop( $args );
			$after_item_key = (string) array_pop( $args );
			$session_id     = array_shift( $args );
			$statuses       = $args;
			$rows           = $this->filter_rows_after_item_key( $rows, $session_id, $statuses, $after_item_key );
			usort( $rows, array( $this, 'compare_rows_by_item_key_ascending' ) );
			$rows = array_slice( $rows, 0, $limit );
		} elseif ( false !== strpos( $prepared['query'], 'WHERE session_id = %s AND status IN' ) && false !== strpos( $prepared['query'], 'ORDER BY item_key ASC LIMIT %d' ) ) {
			$limit      = (int) array_pop( $args );
			$session_id = array_shift( $args );
			$statuses   = $args;
			$rows       = $this->filter_rows_after_item_key( $rows, $session_id, $statuses, '' );
			usort( $rows, array( $this, 'compare_rows_by_item_key_ascending' ) );
			$rows = array_slice( $rows, 0, $limit );
		} elseif ( false !== strpos( $prepared['query'], 'WHERE session_id = %s AND status IN' ) ) {
			$session_id = array_shift( $args );
			$limit      = null;

			if ( false !== strpos( $prepared['query'], 'LIMIT %d' ) ) {
				$limit = (int) array_pop( $args );
			}

			$statuses = $args;
			$rows     = array_values(
				array_filter(
					$rows,
					function ( array $row ) use ( $session_id, $statuses ) {
						return isset( $row['session_id'], $row['status'] )
							&& $row['session_id'] === $session_id
							&& in_array( $row['status'], $statuses, true );
					}
				)
			);
			usort( $rows, array( $this, 'compare_rows_by_updated_at_then_id_ascending' ) );

			if ( null !== $limit ) {
				$rows = array_slice( $rows, 0, $limit );
			}
		} elseif ( false !== strpos( $prepared['query'], 'WHERE status IN' ) ) {
			$limit    = (int) array_pop( $args );
			$statuses = $args;
			$rows     = array_values(
				array_filter(
					$rows,
					function ( array $row ) use ( $statuses ) {
						return in_array( $row['status'], $statuses, true );
					}
				)
			);
			usort( $rows, array( $this, 'compare_rows_by_updated_at_ascending' ) );
			$rows = array_slice( $rows, 0, $limit );
		} elseif ( false !== strpos( $prepared['query'], 'ORDER BY updated_at DESC LIMIT %d' ) ) {
			usort( $rows, array( $this, 'compare_rows_by_updated_at_descending' ) );
			$rows = array_slice( $rows, 0, (int) $args[0] );
		} elseif ( false !== strpos( $prepared['query'], 'decision_key LIKE %s' ) && false !== strpos( $prepared['query'], 'NOT EXISTS' ) ) {
			$rows = $this->filter_unapplied_resolved_decisions_by_key_prefix(
				$rows,
				$prepared['query'],
				$args[0],
				$args[1],
				$args[2],
				$args[3]
			);
			usort( $rows, array( $this, 'compare_rows_by_id_ascending' ) );
			$rows = array_slice( $rows, 0, (int) $args[4] );
		} elseif ( false !== strpos( $prepared['query'], 'idempotency_key = %s' ) ) {
			$rows = $this->filter_rows(
				$rows,
				array(
					'session_id'      => $args[0],
					'idempotency_key' => $args[1],
				)
			);
		} elseif ( false !== strpos( $prepared['query'], 'decision_key = %s' ) ) {
			$rows = $this->filter_rows(
				$rows,
				array(
					'session_id'   => $args[0],
					'decision_key' => $args[1],
				)
			);
		} elseif ( false !== strpos( $prepared['query'], 'status = %s ORDER BY id ASC' ) ) {
			$rows = $this->filter_rows(
				$rows,
				array(
					'session_id' => $args[0],
					'status'     => $args[1],
				)
			);
			usort( $rows, array( $this, 'compare_rows_by_id_ascending' ) );
			if ( false !== strpos( $prepared['query'], 'LIMIT %d' ) ) {
				$rows = array_slice( $rows, 0, (int) $args[2] );
			}
		} elseif ( false !== strpos( $prepared['query'], 'WHERE session_id = %s ORDER BY updated_at DESC, id DESC LIMIT %d' ) ) {
			$rows = $this->filter_rows( $rows, array( 'session_id' => $args[0] ) );
			usort( $rows, array( $this, 'compare_rows_by_updated_at_then_id_descending' ) );
			$rows = array_slice( $rows, 0, (int) $args[1] );
		} elseif ( false !== strpos( $prepared['query'], 'WHERE session_id = %s AND source_item_key > %s ORDER BY source_item_key ASC LIMIT %d' ) ) {
			$rows = $this->filter_rows_after_source_item_key( $rows, $args[0], $args[1] );
			usort( $rows, array( $this, 'compare_rows_by_source_item_key_ascending' ) );
			$rows = array_slice( $rows, 0, (int) $args[2] );
		} elseif ( false !== strpos( $prepared['query'], 'WHERE session_id = %s ORDER BY source_item_key ASC LIMIT %d' ) ) {
			$rows = $this->filter_rows( $rows, array( 'session_id' => $args[0] ) );
			usort( $rows, array( $this, 'compare_rows_by_source_item_key_ascending' ) );
			$rows = array_slice( $rows, 0, (int) $args[1] );
		} elseif ( false !== strpos( $prepared['query'], 'WHERE session_id = %s ORDER BY updated_at ASC, id ASC LIMIT %d' ) ) {
			$rows = $this->filter_rows( $rows, array( 'session_id' => $args[0] ) );
			usort( $rows, array( $this, 'compare_rows_by_updated_at_then_id_ascending' ) );
			$rows = array_slice( $rows, 0, (int) $args[1] );
		} elseif ( false !== strpos( $prepared['query'], 'ORDER BY id DESC LIMIT %d' ) ) {
			$rows = $this->filter_rows( $rows, array( 'session_id' => $args[0] ) );
			usort( $rows, array( $this, 'compare_rows_by_id_descending' ) );
			$rows = array_slice( $rows, 0, (int) $args[1] );
		} elseif ( false !== strpos( $prepared['query'], 'WHERE session_id = %s' ) ) {
			$rows = $this->filter_rows( $rows, array( 'session_id' => $args[0] ) );
		}

		return array_values( $rows );
	}

	/**
	 * Returns table rows for assertions.
	 *
	 * @param string $table Table name.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_table_rows( $table ) {
		$this->ensure_table( $table );

		return $this->tables[ $table ];
	}

	/**
	 * Returns update calls for assertions.
	 *
	 * @param string $table Table name.
	 * @return array<int,array{data:array<string,mixed>,where:array<string,mixed>}>
	 */
	public function get_update_calls( $table ) {
		return isset( $this->updates[ $table ] ) ? $this->updates[ $table ] : array();
	}

	/**
	 * Mutates a fake row for corruption-path tests.
	 *
	 * @param string              $table Table name.
	 * @param array<string,mixed> $where Equality conditions.
	 * @param string              $key   Row key to update.
	 * @param mixed               $value New value.
	 * @return void
	 */
	public function set_row_value( $table, array $where, $key, $value ) {
		$this->ensure_table( $table );

		foreach ( $this->tables[ $table ] as &$row ) {
			if ( $this->row_matches( $row, $where ) ) {
				$row[ $key ] = $value;
			}
		}

		$this->persist();
	}

	/**
	 * Persists the current fake database snapshot when enabled.
	 *
	 * @return void
	 */
	private function persist() {
		if ( null === $this->persistence_path ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.DiscouragedPHPFunctions -- Test-only write-through snapshot for child-process recovery tests.
		file_put_contents( $this->persistence_path, serialize( $this ) );
	}

	/**
	 * Handles fake lock acquisition.
	 *
	 * @param string           $query Query with table name.
	 * @param array<int,mixed> $args  Prepared query arguments.
	 * @return int
	 */
	private function acquire_lock( $query, array $args ) {
		$table = $this->extract_update_table_name( $query );
		$this->ensure_table( $table );

		$owner      = $args[0];
		$token      = $args[1];
		$expires_at = $args[2];
		$updated_at = $args[3];
		$session_id = $args[4];
		$now        = $args[5];

		foreach ( $this->tables[ $table ] as &$row ) {
			if ( $row['id'] !== $session_id ) {
				continue;
			}

			if ( isset( $row['locked_until'] ) && null !== $row['locked_until'] && $row['locked_until'] >= $now ) {
				return 0;
			}

			$row['lock_owner']   = $owner;
			$row['lock_token']   = $token;
			$row['locked_until'] = $expires_at;
			$row['updated_at']   = $updated_at;

			return 1;
		}

		return 0;
	}

	/**
	 * Handles fake lock refresh.
	 *
	 * @param string           $query Query with table name.
	 * @param array<int,mixed> $args  Prepared query arguments.
	 * @return int
	 */
	private function refresh_lock( $query, array $args ) {
		$table = $this->extract_update_table_name( $query );
		$this->ensure_table( $table );

		$new_token  = $args[0];
		$expires_at = $args[1];
		$updated_at = $args[2];
		$session_id = $args[3];
		$old_token  = $args[4];

		foreach ( $this->tables[ $table ] as &$row ) {
			if ( $row['id'] !== $session_id || $row['lock_token'] !== $old_token ) {
				continue;
			}

			$row['lock_token']   = $new_token;
			$row['locked_until'] = $expires_at;
			$row['updated_at']   = $updated_at;

			return 1;
		}

		return 0;
	}

	/**
	 * Handles fake lock release.
	 *
	 * @param string           $query Query with table name.
	 * @param array<int,mixed> $args  Prepared query arguments.
	 * @return int
	 */
	private function release_lock( $query, array $args ) {
		$table = $this->extract_update_table_name( $query );
		$this->ensure_table( $table );

		$updated_at = $args[0];
		$session_id = $args[1];
		$token      = $args[2];

		foreach ( $this->tables[ $table ] as &$row ) {
			if ( $row['id'] !== $session_id || $row['lock_token'] !== $token ) {
				continue;
			}

			$row['lock_owner']   = null;
			$row['lock_token']   = null;
			$row['locked_until'] = null;
			$row['updated_at']   = $updated_at;

			return 1;
		}

		return 0;
	}

	/**
	 * Ensures an in-memory table exists.
	 *
	 * @param string $table Table name.
	 * @return void
	 */
	private function ensure_table( $table ) {
		if ( ! isset( $this->tables[ $table ] ) ) {
			$this->tables[ $table ] = array();
		}

		if ( ! isset( $this->auto_increment[ $table ] ) ) {
			$this->auto_increment[ $table ] = 1;
		}
	}

	/**
	 * Whether a table uses fake auto-increment ids.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	private function is_auto_increment_table( $table ) {
		return false !== strpos( $table, 'decisions' ) || false !== strpos( $table, 'events' ) || false !== strpos( $table, 'source_items' ) || false !== strpos( $table, 'documents' ) || false !== strpos( $table, 'media' );
	}

	/**
	 * Filters rows by equality conditions.
	 *
	 * @param array<int,array<string,mixed>> $rows  Rows to filter.
	 * @param array<string,mixed>            $where Equality conditions.
	 * @return array<int,array<string,mixed>>
	 */
	private function filter_rows( array $rows, array $where ) {
		return array_values(
			array_filter(
				$rows,
				function ( array $row ) use ( $where ) {
					return $this->row_matches( $row, $where );
				}
			)
		);
	}

	/**
	 * Filters rows by session and source item key keyset cursor.
	 *
	 * @param array<int,array<string,mixed>> $rows                  Rows to filter.
	 * @param string                         $session_id            Session id.
	 * @param string                         $after_source_item_key Cursor source item key.
	 * @return array<int,array<string,mixed>>
	 */
	private function filter_rows_after_source_item_key( array $rows, $session_id, $after_source_item_key ) {
		return array_values(
			array_filter(
				$rows,
				function ( array $row ) use ( $session_id, $after_source_item_key ) {
					return isset( $row['session_id'], $row['source_item_key'] )
						&& $row['session_id'] === $session_id
						&& strcmp( (string) $row['source_item_key'], (string) $after_source_item_key ) > 0;
				}
			)
		);
	}

	/**
	 * Filters rows by session, statuses, and source item key cursor.
	 *
	 * @param array<int,array<string,mixed>> $rows           Rows to filter.
	 * @param string                         $session_id     Session id.
	 * @param array<int,string>              $statuses       Source item statuses.
	 * @param string                         $after_item_key Cursor item key.
	 * @return array<int,array<string,mixed>>
	 */
	private function filter_rows_after_item_key( array $rows, $session_id, array $statuses, $after_item_key ) {
		return array_values(
			array_filter(
				$rows,
				function ( array $row ) use ( $session_id, $statuses, $after_item_key ) {
					return isset( $row['session_id'], $row['status'], $row['item_key'] )
						&& $row['session_id'] === $session_id
						&& in_array( $row['status'], $statuses, true )
						&& ( '' === $after_item_key || strcmp( (string) $row['item_key'], (string) $after_item_key ) > 0 );
				}
			)
		);
	}

	/**
	 * Filters media rows by session, statuses, and reference-key cursor.
	 *
	 * @param array<int,array<string,mixed>> $rows                Rows to filter.
	 * @param string                         $session_id          Session id.
	 * @param array<int,string>              $statuses            Media reference statuses.
	 * @param string                         $after_reference_key Cursor reference key.
	 * @return array<int,array<string,mixed>>
	 */
	private function filter_rows_after_reference_key( array $rows, $session_id, array $statuses, $after_reference_key ) {
		return array_values(
			array_filter(
				$rows,
				function ( array $row ) use ( $session_id, $statuses, $after_reference_key ) {
					return isset( $row['session_id'], $row['status'], $row['reference_key'] )
						&& $row['session_id'] === $session_id
						&& in_array( $row['status'], $statuses, true )
						&& ( '' === $after_reference_key || strcmp( (string) $row['reference_key'], (string) $after_reference_key ) > 0 );
				}
			)
		);
	}

	/**
	 * Filters resolved decisions by key prefix while excluding applied idempotency rows.
	 *
	 * @param array<int,array<string,mixed>> $rows                   Decision rows.
	 * @param string                         $query                  Query string.
	 * @param string                         $session_id             Session id.
	 * @param string                         $status                 Decision status.
	 * @param string                         $decision_key_like      Decision LIKE pattern.
	 * @param string                         $idempotency_key_prefix Idempotency key prefix.
	 * @return array<int,array<string,mixed>>
	 */
	private function filter_unapplied_resolved_decisions_by_key_prefix( array $rows, $query, $session_id, $status, $decision_key_like, $idempotency_key_prefix ) {
		$idempotency_table = $this->extract_idempotency_table_name( $query );
		$this->ensure_table( $idempotency_table );

		$decision_key_prefix = '%' === substr( (string) $decision_key_like, -1 )
			? substr( (string) $decision_key_like, 0, -1 )
			: (string) $decision_key_like;

		return array_values(
			array_filter(
				$rows,
				function ( array $row ) use ( $session_id, $status, $decision_key_prefix, $idempotency_key_prefix, $idempotency_table ) {
					if (
						! isset( $row['session_id'], $row['status'], $row['decision_key'] )
						|| $row['session_id'] !== $session_id
						|| $row['status'] !== $status
						|| 0 !== strpos( (string) $row['decision_key'], $decision_key_prefix )
					) {
						return false;
					}

					$idempotency_key = (string) $idempotency_key_prefix . (string) $row['decision_key'];

					foreach ( $this->tables[ $idempotency_table ] as $idempotency_row ) {
						if (
							isset( $idempotency_row['session_id'], $idempotency_row['idempotency_key'] )
							&& $idempotency_row['session_id'] === $session_id
							&& $idempotency_row['idempotency_key'] === $idempotency_key
						) {
							return false;
						}
					}

					return true;
				}
			)
		);
	}

	/**
	 * Checks whether a row matches equality conditions.
	 *
	 * @param array<string,mixed> $row   Row to check.
	 * @param array<string,mixed> $where Equality conditions.
	 * @return bool
	 */
	private function row_matches( array $row, array $where ) {
		foreach ( $where as $key => $value ) {
			if ( ! array_key_exists( $key, $row ) || $row[ $key ] !== $value ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Extracts a table name from a SELECT query.
	 *
	 * @param string $query Query string.
	 * @return string
	 */
	private function extract_table_name( $query ) {
		preg_match( '/FROM\s+([^\s]+)/', $query, $matches );

		return $matches[1];
	}

	/**
	 * Extracts the idempotency table name from a NOT EXISTS subquery.
	 *
	 * @param string $query Query string.
	 * @return string
	 */
	private function extract_idempotency_table_name( $query ) {
		preg_match_all( '/FROM\s+([^\s]+)/', $query, $matches );

		return isset( $matches[1][1] ) ? $matches[1][1] : str_replace( 'decisions', 'idempotency', $this->extract_table_name( $query ) );
	}

	/**
	 * Extracts a table name from an UPDATE query.
	 *
	 * @param string $query Query string.
	 * @return string
	 */
	private function extract_update_table_name( $query ) {
		preg_match( '/UPDATE\s+([^\s]+)/', $query, $matches );

		return $matches[1];
	}

	/**
	 * Compares rows by ascending id.
	 *
	 * @param array<string,mixed> $left  Left row.
	 * @param array<string,mixed> $right Right row.
	 * @return int
	 */
	private function compare_rows_by_id_ascending( array $left, array $right ) {
		return (int) $left['id'] - (int) $right['id'];
	}

	/**
	 * Compares rows by descending id.
	 *
	 * @param array<string,mixed> $left  Left row.
	 * @param array<string,mixed> $right Right row.
	 * @return int
	 */
	private function compare_rows_by_id_descending( array $left, array $right ) {
		return (int) $right['id'] - (int) $left['id'];
	}

	/**
	 * Compares rows by ascending updated_at timestamp.
	 *
	 * @param array<string,mixed> $left  Left row.
	 * @param array<string,mixed> $right Right row.
	 * @return int
	 */
	private function compare_rows_by_updated_at_ascending( array $left, array $right ) {
		return strcmp( (string) $left['updated_at'], (string) $right['updated_at'] );
	}

	/**
	 * Compares rows by descending updated_at timestamp.
	 *
	 * @param array<string,mixed> $left  Left row.
	 * @param array<string,mixed> $right Right row.
	 * @return int
	 */
	private function compare_rows_by_updated_at_descending( array $left, array $right ) {
		return strcmp( (string) $right['updated_at'], (string) $left['updated_at'] );
	}

	/**
	 * Compares rows by ascending update time then id.
	 *
	 * @param array<string,mixed> $left  Left row.
	 * @param array<string,mixed> $right Right row.
	 * @return int
	 */
	private function compare_rows_by_updated_at_then_id_ascending( array $left, array $right ) {
		$updated = strcmp( (string) $left['updated_at'], (string) $right['updated_at'] );

		if ( 0 !== $updated ) {
			return $updated;
		}

		return (int) $left['id'] - (int) $right['id'];
	}

	/**
	 * Compares rows by descending update time then id.
	 *
	 * @param array<string,mixed> $left  Left row.
	 * @param array<string,mixed> $right Right row.
	 * @return int
	 */
	private function compare_rows_by_updated_at_then_id_descending( array $left, array $right ) {
		$updated = strcmp( (string) $right['updated_at'], (string) $left['updated_at'] );

		if ( 0 !== $updated ) {
			return $updated;
		}

		return (int) $right['id'] - (int) $left['id'];
	}

	/**
	 * Compares rows by ascending source item key.
	 *
	 * @param array<string,mixed> $left  Left row.
	 * @param array<string,mixed> $right Right row.
	 * @return int
	 */
	private function compare_rows_by_source_item_key_ascending( array $left, array $right ) {
		return strcmp( (string) $left['source_item_key'], (string) $right['source_item_key'] );
	}

	/**
	 * Compares rows by ascending source item key.
	 *
	 * @param array<string,mixed> $left  Left row.
	 * @param array<string,mixed> $right Right row.
	 * @return int
	 */
	private function compare_rows_by_item_key_ascending( array $left, array $right ) {
		return strcmp( (string) $left['item_key'], (string) $right['item_key'] );
	}

	/**
	 * Compares rows by ascending media reference key.
	 *
	 * @param array<string,mixed> $left  Left row.
	 * @param array<string,mixed> $right Right row.
	 * @return int
	 */
	private function compare_rows_by_reference_key_ascending( array $left, array $right ) {
		return strcmp( (string) $left['reference_key'], (string) $right['reference_key'] );
	}
}
