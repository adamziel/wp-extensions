<?php
/**
 * Prepared document to WordPress post persister.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

use RuntimeException;

/**
 * Persists staged block markup as idempotent WordPress pages.
 */
final class ImportPostPersister {
	const DEFAULT_DOCUMENT_LIMIT = 25;

	/**
	 * Durable import store.
	 *
	 * @var WordPressImportSessionStore
	 */
	private $store;

	/**
	 * WordPress post gateway.
	 *
	 * @var ImportPostGatewayInterface
	 */
	private $posts;

	/**
	 * Hidden failure simulation controls for adversarial tests.
	 *
	 * @var ImportRunnerControls
	 */
	private $controls;

	/**
	 * Whether prepared documents must have completed prior downstream stages.
	 *
	 * @var bool
	 */
	private $require_stage_readiness;

	/**
	 * Constructor.
	 *
	 * @param WordPressImportSessionStore     $store Durable store.
	 * @param ImportPostGatewayInterface|null $posts Optional post gateway.
	 * @param ImportRunnerControls|null       $controls Optional hidden test controls.
	 * @param bool                            $require_stage_readiness Whether to require prior stage completion.
	 */
	public function __construct( WordPressImportSessionStore $store, ImportPostGatewayInterface $posts = null, ImportRunnerControls $controls = null, $require_stage_readiness = false ) {
		$this->store                   = $store;
		$this->posts                   = null === $posts ? new WordPressPostGateway() : $posts;
		$this->controls                = null === $controls ? ImportRunnerControls::none() : $controls;
		$this->require_stage_readiness = (bool) $require_stage_readiness;
	}

	/**
	 * Advances post persistence for prepared documents.
	 *
	 * @param ImportSession $session Session.
	 * @param int           $limit   Maximum documents to inspect.
	 * @return array{created:int,updated:int,skipped:int,failed:int,message:string}
	 */
	public function advance( ImportSession $session, $limit = self::DEFAULT_DOCUMENT_LIMIT ) {
		$limit   = max( 1, min( 250, (int) $limit ) );
		$summary = array(
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
			'failed'  => 0,
			'message' => 'No prepared documents were ready for post persistence.',
		);

		if ( ! $this->posts->is_available() ) {
			$summary['message'] = $this->posts->get_unavailable_reason();
			return $summary;
		}

		$after_source_item_key = null;
		$attempted             = 0;
		$confirmed_domains     = $this->confirmed_domains( $session );

		do {
			$documents      = $this->store->list_prepared_documents_after_source_item_key(
				$session->get_id(),
				$after_source_item_key,
				$limit
			);
			$document_count = count( $documents );

			foreach ( $documents as $document ) {
				$after_source_item_key = $document->get_source_item_key();

				if ( ! $this->document_ready_for_persistence( $document, $confirmed_domains ) ) {
					continue;
				}

				$result = $this->persist_document( $session, $document );
				++$summary[ $result ];

				if ( 'skipped' === $result ) {
					continue;
				}

				++$attempted;
				if ( $attempted >= $limit ) {
					break 2;
				}
			}
		} while ( $document_count === $limit );

		if ( 0 < $summary['created'] || 0 < $summary['updated'] || 0 < $summary['skipped'] || 0 < $summary['failed'] ) {
			$summary['message'] = 'Prepared document post persistence inspected staged documents.';
		}

		return $summary;
	}

	/**
	 * Returns confirmed first-party domains from the resolved URL decision.
	 *
	 * @param ImportSession $session Session.
	 * @return array<int,string>
	 */
	private function confirmed_domains( ImportSession $session ) {
		$decision = $this->store->find_decision( $session->get_id(), ImportUrlInference::DECISION_KEY );

		if ( null === $decision || ImportDecision::STATUS_RESOLVED !== $decision->get_status() ) {
			return array();
		}

		$answer = $decision->get_answer();

		if ( ! isset( $answer['confirmed_domains'] ) || ! is_array( $answer['confirmed_domains'] ) ) {
			return array();
		}

		$domains = array();
		foreach ( $answer['confirmed_domains'] as $domain ) {
			$domain = strtolower( trim( (string) $domain ) );
			if ( '' !== $domain ) {
				$domains[] = $domain;
			}
		}

		$domains = array_values( array_unique( $domains ) );
		sort( $domains, SORT_STRING );

		return $domains;
	}

	/**
	 * Returns whether a prepared document has passed required downstream stages.
	 *
	 * @param ImportPreparedDocument $document          Prepared document.
	 * @param array<int,string>      $confirmed_domains Confirmed first-party domains.
	 * @return bool
	 */
	private function document_ready_for_persistence( ImportPreparedDocument $document, array $confirmed_domains ) {
		if ( ! $this->require_stage_readiness ) {
			return true;
		}

		if ( ! $this->media_detection_complete_for_target( $document, $confirmed_domains ) ) {
			return false;
		}

		if ( empty( $confirmed_domains ) ) {
			return true;
		}

		$metadata = $document->get_metadata();

		if ( empty( $metadata['url_rewriting'] ) || ! is_array( $metadata['url_rewriting'] ) ) {
			return false;
		}

		$state = $metadata['url_rewriting'];

		return ! empty( $state['complete'] )
			&& isset( $state['confirmed_domains'] )
			&& array_values( $confirmed_domains ) === $state['confirmed_domains'];
	}

	/**
	 * Returns whether media detection has inspected this document for the current inputs.
	 *
	 * @param ImportPreparedDocument $document          Prepared document.
	 * @param array<int,string>      $confirmed_domains Confirmed first-party domains.
	 * @return bool
	 */
	private function media_detection_complete_for_target( ImportPreparedDocument $document, array $confirmed_domains ) {
		$metadata = $document->get_metadata();

		if ( empty( $metadata['media_detection'] ) || ! is_array( $metadata['media_detection'] ) ) {
			return false;
		}

		$state = $metadata['media_detection'];

		return ! empty( $state['complete'] )
			&& isset( $state['confirmed_domains'] )
			&& array_values( $confirmed_domains ) === $state['confirmed_domains'];
	}

	/**
	 * Persists one prepared document.
	 *
	 * @param ImportSession          $session  Session.
	 * @param ImportPreparedDocument $document Prepared document.
	 * @return string Summary bucket.
	 * @throws ImportSimulatedCrashException When a controlled crash is requested.
	 */
	private function persist_document( ImportSession $session, ImportPreparedDocument $document ) {
		$idempotency_key = 'post:' . $document->get_source_item_key();
		$record          = $this->store->find_idempotency_record( $session->get_id(), $idempotency_key );

		if ( null !== $record && $record->get_payload_hash() === $document->get_content_hash() ) {
			return 'skipped';
		}

		$existing_post_id = null === $record ? null : (int) $record->get_resource_id();

		if ( null === $existing_post_id || $existing_post_id < 1 ) {
			$existing_post_id = $this->posts->find_existing_post_id( $session->get_id(), $document->get_source_item_key() );
		}

		try {
			$post_id = $this->posts->insert_or_update( $document, $existing_post_id );
			$this->record_relationship_diagnostics( $session, $document, $post_id );

			if ( $this->controls->should_simulate_fatal_after_post_write() ) {
				$this->store->record_event(
					$session->get_id(),
					new ImportProgressEvent(
						ImportProgressEvent::LEVEL_ERROR,
						'post.simulated_fatal_after_write',
						'Post write completed, then PHP exited before recording idempotency.',
						array(
							'post_id'         => $post_id,
							'source_item_key' => $document->get_source_item_key(),
							'content_hash'    => $document->get_content_hash(),
						)
					)
				);

				exit( 119 );
			}

			if ( $this->controls->should_simulate_post_idempotency_crash() ) {
				$this->store->record_event(
					$session->get_id(),
					new ImportProgressEvent(
						ImportProgressEvent::LEVEL_ERROR,
						'post.simulated_crash_after_write',
						'Post write completed, then the importer crashed before recording idempotency.',
						array(
							'post_id'         => $post_id,
							'source_item_key' => $document->get_source_item_key(),
							'content_hash'    => $document->get_content_hash(),
						)
					)
				);

				throw new ImportSimulatedCrashException( 'Simulated importer crash after post write and before idempotency record.' );
			}

			$this->store->remember_idempotency_record(
				$session->get_id(),
				new ImportIdempotencyRecord(
					$idempotency_key,
					'post',
					(string) $post_id,
					$document->get_content_hash()
				)
			);

			$this->store->record_event(
				$session->get_id(),
				new ImportProgressEvent(
					ImportProgressEvent::LEVEL_INFO,
					null === $existing_post_id ? 'post.created' : 'post.updated',
					null === $existing_post_id ? 'Prepared document was inserted as a WordPress draft page.' : 'Prepared document changed; the existing WordPress draft page was updated.',
					array(
						'post_id'         => $post_id,
						'source_item_key' => $document->get_source_item_key(),
						'content_hash'    => $document->get_content_hash(),
					)
				)
			);

			return null === $existing_post_id ? 'created' : 'updated';
		} catch ( ImportSimulatedCrashException $exception ) {
			throw $exception;
		} catch ( RuntimeException $exception ) {
			$this->store->record_event(
				$session->get_id(),
				new ImportProgressEvent(
					ImportProgressEvent::LEVEL_ERROR,
					'post.failed',
					$exception->getMessage(),
					array(
						'source_item_key' => $document->get_source_item_key(),
						'content_hash'    => $document->get_content_hash(),
					)
				)
			);

			return 'failed';
		}
	}

	/**
	 * Records post relationship diagnostics from the gateway.
	 *
	 * @param ImportSession          $session  Session.
	 * @param ImportPreparedDocument $document Prepared document.
	 * @param int                    $post_id  Post id.
	 * @return void
	 */
	private function record_relationship_diagnostics( ImportSession $session, ImportPreparedDocument $document, $post_id ) {
		$diagnostics = $this->posts->get_last_relationship_diagnostics();

		if ( empty( $diagnostics ) ) {
			return;
		}

		$has_unmapped = ImportRelationshipMappingDecision::has_unmapped_relationships( $diagnostics );

		if ( $has_unmapped ) {
			$this->save_relationship_mapping_decision( $session, $document, $post_id, $diagnostics );
		}

		$this->store->record_event(
			$session->get_id(),
			new ImportProgressEvent(
				$has_unmapped ? ImportProgressEvent::LEVEL_WARNING : ImportProgressEvent::LEVEL_INFO,
				$has_unmapped ? ImportRelationshipMappingDecision::WARNING_EVENT : 'post.relationships_mapped',
				$has_unmapped ? 'Remote REST author or taxonomy relationships need operator review after draft creation.' : 'Remote REST author and taxonomy relationships were applied to the imported draft.',
				array(
					'post_id'         => (int) $post_id,
					'source_item_key' => $document->get_source_item_key(),
					'relationships'   => $diagnostics,
				)
			)
		);
	}

	/**
	 * Stores a pending operator decision for explicit REST relationship mapping.
	 *
	 * @param ImportSession          $session     Session.
	 * @param ImportPreparedDocument $document    Prepared document.
	 * @param int                    $post_id     Post id.
	 * @param array<string,mixed>    $diagnostics Relationship diagnostics.
	 * @return void
	 */
	private function save_relationship_mapping_decision( ImportSession $session, ImportPreparedDocument $document, $post_id, array $diagnostics ) {
		$key = ImportRelationshipMappingDecision::decision_key( $document->get_source_item_key() );

		if ( null !== $this->store->find_decision( $session->get_id(), $key ) ) {
			return;
		}

		$this->store->save_decision(
			$session->get_id(),
			ImportRelationshipMappingDecision::pending_decision( $post_id, $document, $diagnostics )
		);
	}
}
