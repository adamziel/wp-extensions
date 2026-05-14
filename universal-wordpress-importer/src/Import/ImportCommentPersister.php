<?php
/**
 * Staged REST comment to WordPress comment persister.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

use RuntimeException;

/**
 * Persists staged remote comments against already-imported draft posts.
 */
final class ImportCommentPersister {
	const DEFAULT_DOCUMENT_LIMIT = 25;

	/**
	 * Durable import store.
	 *
	 * @var WordPressImportSessionStore
	 */
	private $store;

	/**
	 * WordPress post gateway for locating imported posts.
	 *
	 * @var ImportPostGatewayInterface
	 */
	private $posts;

	/**
	 * WordPress comment gateway.
	 *
	 * @var ImportCommentGatewayInterface
	 */
	private $comments;

	/**
	 * Hidden failure simulation controls for adversarial tests.
	 *
	 * @var ImportRunnerControls
	 */
	private $controls;

	/**
	 * Constructor.
	 *
	 * @param WordPressImportSessionStore        $store    Durable store.
	 * @param ImportPostGatewayInterface|null    $posts    Optional post gateway.
	 * @param ImportCommentGatewayInterface|null $comments Optional comment gateway.
	 * @param ImportRunnerControls|null          $controls Optional hidden test controls.
	 */
	public function __construct( WordPressImportSessionStore $store, ImportPostGatewayInterface $posts = null, ImportCommentGatewayInterface $comments = null, ImportRunnerControls $controls = null ) {
		$this->store    = $store;
		$this->posts    = null === $posts ? new WordPressPostGateway() : $posts;
		$this->comments = null === $comments ? new WordPressCommentGateway() : $comments;
		$this->controls = null === $controls ? ImportRunnerControls::none() : $controls;
	}

	/**
	 * Advances comment persistence for prepared documents.
	 *
	 * @param ImportSession $session Session.
	 * @param int           $limit   Maximum documents to inspect.
	 * @return array{created:int,updated:int,skipped:int,deferred:int,failed:int,message:string}
	 */
	public function advance( ImportSession $session, $limit = self::DEFAULT_DOCUMENT_LIMIT ) {
		$limit   = max( 1, min( 250, (int) $limit ) );
		$summary = array(
			'created'  => 0,
			'updated'  => 0,
			'skipped'  => 0,
			'deferred' => 0,
			'failed'   => 0,
			'message'  => 'No staged REST comments were ready for persistence.',
		);

		if ( ! $this->posts->is_available() ) {
			$summary['message'] = $this->posts->get_unavailable_reason();
			return $summary;
		}

		if ( ! $this->comments->is_available() ) {
			$summary['message'] = $this->comments->get_unavailable_reason();
			return $summary;
		}

		$after_source_item_key = null;
		$processed_documents   = 0;

		do {
			$documents      = $this->store->list_prepared_documents_after_source_item_key(
				$session->get_id(),
				$after_source_item_key,
				$limit
			);
			$document_count = count( $documents );

			foreach ( $documents as $document ) {
				$after_source_item_key = $document->get_source_item_key();
				$metadata              = $document->get_metadata();

				if ( empty( $metadata['remote_comments'] ) || ! is_array( $metadata['remote_comments'] ) ) {
					continue;
				}

				$document_had_action = false;

				foreach ( $metadata['remote_comments'] as $comment ) {
					if ( ! is_array( $comment ) ) {
						++$summary['skipped'];
						continue;
					}

					$result = $this->persist_comment( $session, $document, $comment );
					++$summary[ $result ];

					if ( 'skipped' !== $result ) {
						$document_had_action = true;
					}

					$document = $this->store->find_prepared_document( $session->get_id(), $document->get_source_item_key() );

					if ( null === $document ) {
						break;
					}
				}

				if ( $document_had_action ) {
					++$processed_documents;

					if ( $processed_documents >= $limit ) {
						break 2;
					}
				}
			}
		} while ( $document_count === $limit );

		if ( 0 < $summary['created'] || 0 < $summary['updated'] || 0 < $summary['skipped'] || 0 < $summary['deferred'] || 0 < $summary['failed'] ) {
			$summary['message'] = 'Staged REST comment persistence inspected prepared documents.';
		}

		return $summary;
	}

	/**
	 * Persists one staged comment.
	 *
	 * @param ImportSession          $session  Session.
	 * @param ImportPreparedDocument $document Prepared document.
	 * @param array<string,mixed>    $comment  Staged remote comment metadata.
	 * @return string Summary bucket.
	 * @throws ImportSimulatedCrashException When a controlled crash is requested.
	 */
	private function persist_comment( ImportSession $session, ImportPreparedDocument $document, array $comment ) {
		$remote_comment_id = isset( $comment['remote_comment_id'] ) ? (int) $comment['remote_comment_id'] : ( isset( $comment['id'] ) ? (int) $comment['id'] : 0 );

		if ( $remote_comment_id < 1 ) {
			$this->record_event(
				$session,
				ImportProgressEvent::LEVEL_WARNING,
				'comment.skipped_invalid',
				'Staged REST comment is missing a valid remote comment id.',
				$document,
				$comment
			);
			return 'skipped';
		}

		$idempotency_key = $this->idempotency_key( $document->get_source_item_key(), $remote_comment_id );
		$payload_hash    = $this->comment_hash( $document, $comment );
		$record          = $this->store->find_idempotency_record( $session->get_id(), $idempotency_key );

		if ( null !== $record && $record->get_payload_hash() === $payload_hash ) {
			return 'skipped';
		}

		$post_id = $this->find_post_id( $session, $document );

		if ( null === $post_id ) {
			$this->record_event(
				$session,
				ImportProgressEvent::LEVEL_WARNING,
				'comment.deferred_post_missing',
				'Staged REST comment is waiting for its imported draft post before it can be created.',
				$document,
				$comment
			);
			return 'deferred';
		}

		$parent_comment_id = $this->find_parent_comment_id( $session, $document, $comment );

		if ( false === $parent_comment_id ) {
			$this->record_event(
				$session,
				ImportProgressEvent::LEVEL_WARNING,
				'comment.deferred_parent_missing',
				'Staged REST comment is waiting for its imported parent comment before it can be created.',
				$document,
				$comment
			);
			return 'deferred';
		}

		$existing_comment_id = null === $record ? null : (int) $record->get_resource_id();

		if ( null === $existing_comment_id || $existing_comment_id < 1 ) {
			$existing_comment_id = $this->comments->find_existing_comment_id( $session->get_id(), $document->get_source_item_key(), $remote_comment_id );
		}

		try {
			$comment_id = $this->comments->insert_or_update( $document, $comment, $post_id, (int) $parent_comment_id, $existing_comment_id );

			if ( $this->controls->should_simulate_fatal_after_comment_write() ) {
				$this->record_event(
					$session,
					ImportProgressEvent::LEVEL_ERROR,
					'comment.simulated_fatal_after_write',
					'Comment write completed, then PHP exited before recording idempotency.',
					$document,
					$comment,
					array(
						'comment_id'      => $comment_id,
						'post_id'         => $post_id,
						'payload_hash'    => $payload_hash,
						'local_parent_id' => (int) $parent_comment_id,
					)
				);

				exit( 121 );
			}

			if ( $this->controls->should_simulate_comment_idempotency_crash() ) {
				$this->record_event(
					$session,
					ImportProgressEvent::LEVEL_ERROR,
					'comment.simulated_crash_after_write',
					'Comment write completed, then the importer crashed before recording idempotency.',
					$document,
					$comment,
					array(
						'comment_id'      => $comment_id,
						'post_id'         => $post_id,
						'payload_hash'    => $payload_hash,
						'local_parent_id' => (int) $parent_comment_id,
					)
				);

				throw new ImportSimulatedCrashException( 'Simulated importer crash after comment write and before idempotency record.' );
			}

			$this->store->remember_idempotency_record(
				$session->get_id(),
				new ImportIdempotencyRecord(
					$idempotency_key,
					'comment',
					(string) $comment_id,
					$payload_hash
				)
			);

			$this->save_local_comment_mapping( $document, $comment, $comment_id, $post_id, (int) $parent_comment_id );

			$this->record_event(
				$session,
				ImportProgressEvent::LEVEL_INFO,
				null === $existing_comment_id ? 'comment.created' : 'comment.updated',
				null === $existing_comment_id ? 'Staged REST comment was imported into WordPress.' : 'Staged REST comment changed; the existing WordPress comment was updated.',
				$document,
				$comment,
				array(
					'comment_id'      => $comment_id,
					'post_id'         => $post_id,
					'payload_hash'    => $payload_hash,
					'local_parent_id' => (int) $parent_comment_id,
				)
			);

			return null === $existing_comment_id ? 'created' : 'updated';
		} catch ( ImportSimulatedCrashException $exception ) {
			throw $exception;
		} catch ( RuntimeException $exception ) {
			$this->record_event(
				$session,
				ImportProgressEvent::LEVEL_ERROR,
				'comment.failed',
				$exception->getMessage(),
				$document,
				$comment,
				array( 'post_id' => $post_id )
			);

			return 'failed';
		}
	}

	/**
	 * Locates the local post id for a prepared document.
	 *
	 * @param ImportSession          $session  Session.
	 * @param ImportPreparedDocument $document Document.
	 * @return int|null
	 */
	private function find_post_id( ImportSession $session, ImportPreparedDocument $document ) {
		$record = $this->store->find_idempotency_record( $session->get_id(), 'post:' . $document->get_source_item_key() );

		if ( null !== $record && (int) $record->get_resource_id() > 0 ) {
			return (int) $record->get_resource_id();
		}

		$post_id = $this->posts->find_existing_post_id( $session->get_id(), $document->get_source_item_key() );

		return null === $post_id || (int) $post_id < 1 ? null : (int) $post_id;
	}

	/**
	 * Locates a local parent comment id.
	 *
	 * @param ImportSession          $session  Session.
	 * @param ImportPreparedDocument $document Document.
	 * @param array<string,mixed>    $comment  Staged comment.
	 * @return int|false Local parent id, zero for top-level, or false to defer.
	 */
	private function find_parent_comment_id( ImportSession $session, ImportPreparedDocument $document, array $comment ) {
		$remote_parent_id = isset( $comment['remote_parent_id'] ) ? (int) $comment['remote_parent_id'] : 0;

		if ( $remote_parent_id < 1 ) {
			return 0;
		}

		$record = $this->store->find_idempotency_record( $session->get_id(), $this->idempotency_key( $document->get_source_item_key(), $remote_parent_id ) );

		if ( null !== $record && (int) $record->get_resource_id() > 0 ) {
			return (int) $record->get_resource_id();
		}

		$existing = $this->comments->find_existing_comment_id( $session->get_id(), $document->get_source_item_key(), $remote_parent_id );

		if ( null !== $existing && (int) $existing > 0 ) {
			return (int) $existing;
		}

		if ( $this->remote_comment_id_is_staged( $document, $remote_parent_id ) ) {
			return false;
		}

		$this->record_event(
			$session,
			ImportProgressEvent::LEVEL_WARNING,
			'comment.parent_unavailable',
			'Remote parent comment was not present in the staged public REST comments; importing this comment as top-level.',
			$document,
			$comment
		);

		return 0;
	}

	/**
	 * Whether a remote comment id is present in the staged metadata.
	 *
	 * @param ImportPreparedDocument $document          Document.
	 * @param int                    $remote_comment_id Remote comment id.
	 * @return bool
	 */
	private function remote_comment_id_is_staged( ImportPreparedDocument $document, $remote_comment_id ) {
		$metadata = $document->get_metadata();

		if ( empty( $metadata['remote_comments'] ) || ! is_array( $metadata['remote_comments'] ) ) {
			return false;
		}

		foreach ( $metadata['remote_comments'] as $comment ) {
			if ( is_array( $comment ) && isset( $comment['remote_comment_id'] ) && (int) $comment['remote_comment_id'] === (int) $remote_comment_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Stores local comment id mappings back onto the prepared document metadata.
	 *
	 * @param ImportPreparedDocument $document          Document.
	 * @param array<string,mixed>    $comment           Staged comment.
	 * @param int                    $comment_id        Local comment id.
	 * @param int                    $post_id           Local post id.
	 * @param int                    $parent_comment_id Local parent comment id.
	 * @return void
	 */
	private function save_local_comment_mapping( ImportPreparedDocument $document, array $comment, $comment_id, $post_id, $parent_comment_id ) {
		$metadata          = $document->get_metadata();
		$remote_comment_id = isset( $comment['remote_comment_id'] ) ? (int) $comment['remote_comment_id'] : 0;

		if ( ! isset( $metadata['local_comments'] ) || ! is_array( $metadata['local_comments'] ) ) {
			$metadata['local_comments'] = array();
		}

		$metadata['local_comments'][ (string) $remote_comment_id ] = array(
			'local_comment_id' => (int) $comment_id,
			'local_post_id'    => (int) $post_id,
			'remote_parent_id' => isset( $comment['remote_parent_id'] ) ? (int) $comment['remote_parent_id'] : 0,
			'local_parent_id'  => (int) $parent_comment_id,
		);

		$metadata['remote_comments_imported'] = count( $metadata['local_comments'] );

		$this->store->save_prepared_document( $document->with_metadata( $metadata ) );
	}

	/**
	 * Records a comment persistence progress event.
	 *
	 * @param ImportSession          $session    Session.
	 * @param string                 $level      Event level.
	 * @param string                 $type       Event type.
	 * @param string                 $message    Event message.
	 * @param ImportPreparedDocument $document   Document.
	 * @param array<string,mixed>    $comment    Staged comment.
	 * @param array<string,mixed>    $extra      Extra context.
	 * @return void
	 */
	private function record_event( ImportSession $session, $level, $type, $message, ImportPreparedDocument $document, array $comment, array $extra = array() ) {
		$this->store->record_event(
			$session->get_id(),
			new ImportProgressEvent(
				$level,
				$type,
				$message,
				array_merge(
					array(
						'source_item_key'   => $document->get_source_item_key(),
						'remote_comment_id' => isset( $comment['remote_comment_id'] ) ? (int) $comment['remote_comment_id'] : 0,
						'remote_parent_id'  => isset( $comment['remote_parent_id'] ) ? (int) $comment['remote_parent_id'] : 0,
					),
					$extra
				)
			)
		);
	}

	/**
	 * Builds an idempotency key for a staged remote comment.
	 *
	 * @param string $source_item_key   Source item key.
	 * @param int    $remote_comment_id Remote comment id.
	 * @return string
	 */
	private function idempotency_key( $source_item_key, $remote_comment_id ) {
		return 'comment:' . (string) $source_item_key . ':' . (int) $remote_comment_id;
	}

	/**
	 * Builds a stable hash for a staged remote comment payload.
	 *
	 * @param ImportPreparedDocument $document Document.
	 * @param array<string,mixed>    $comment  Staged comment.
	 * @return string
	 */
	private function comment_hash( ImportPreparedDocument $document, array $comment ) {
		$payload = array(
			'source_item_key'   => $document->get_source_item_key(),
			'remote_comment_id' => isset( $comment['remote_comment_id'] ) ? (int) $comment['remote_comment_id'] : 0,
			'remote_parent_id'  => isset( $comment['remote_parent_id'] ) ? (int) $comment['remote_parent_id'] : 0,
			'author_name'       => isset( $comment['author_name'] ) ? (string) $comment['author_name'] : '',
			'author_url'        => isset( $comment['author_url'] ) ? (string) $comment['author_url'] : '',
			'content'           => isset( $comment['content'] ) ? (string) $comment['content'] : '',
			'date'              => isset( $comment['date'] ) ? (string) $comment['date'] : '',
			'date_gmt'          => isset( $comment['date_gmt'] ) ? (string) $comment['date_gmt'] : '',
			'status'            => isset( $comment['status'] ) ? (string) $comment['status'] : '',
			'type'              => isset( $comment['type'] ) ? (string) $comment['type'] : '',
		);

		if ( function_exists( 'wp_json_encode' ) ) {
			$json = wp_json_encode( $payload );
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Unit tests run without WordPress.
			$json = json_encode( $payload );
		}

		return hash( 'sha256', is_string( $json ) ? $json : '' );
	}
}
