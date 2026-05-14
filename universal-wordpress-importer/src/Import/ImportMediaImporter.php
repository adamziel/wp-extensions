<?php
/**
 * Queued media attachment importer.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

use RuntimeException;

/**
 * Imports queued local and confirmed first-party media references and rewrites prepared documents to attachment URLs.
 */
final class ImportMediaImporter {
	const DEFAULT_MEDIA_LIMIT = 25;

	/**
	 * Durable import store.
	 *
	 * @var WordPressImportSessionStore
	 */
	private $store;

	/**
	 * WordPress media gateway.
	 *
	 * @var ImportMediaGatewayInterface
	 */
	private $media;

	/**
	 * Hidden failure simulation controls for adversarial tests.
	 *
	 * @var ImportRunnerControls
	 */
	private $controls;

	/**
	 * Constructor.
	 *
	 * @param WordPressImportSessionStore      $store    Durable store.
	 * @param ImportMediaGatewayInterface|null $media    Optional media gateway.
	 * @param ImportRunnerControls|null        $controls Optional hidden test controls.
	 */
	public function __construct( WordPressImportSessionStore $store, ImportMediaGatewayInterface $media = null, ImportRunnerControls $controls = null ) {
		$this->store    = $store;
		$this->media    = null === $media ? new WordPressMediaGateway() : $media;
		$this->controls = null === $controls ? ImportRunnerControls::none() : $controls;
	}

	/**
	 * Advances media import.
	 *
	 * @param ImportSession $session Session.
	 * @param int           $limit   Maximum references to inspect.
	 * @return array{imported:int,rewritten:int,skipped:int,failed:int,blocked:bool,message:string}
	 */
	public function advance( ImportSession $session, $limit = self::DEFAULT_MEDIA_LIMIT ) {
		$limit      = max( 1, min( 250, (int) $limit ) );
		$references = $this->store->list_media_references_by_statuses( $session->get_id(), array( ImportMediaReference::STATUS_QUEUED ), $limit );
		$summary    = array(
			'imported'  => 0,
			'rewritten' => 0,
			'skipped'   => 0,
			'failed'    => 0,
			'blocked'   => false,
			'message'   => 'No queued media references were ready for import.',
		);

		if ( empty( $references ) ) {
			return $summary;
		}

		if ( ! $this->media->is_available() ) {
			$summary['blocked'] = true;
			$summary['message'] = $this->media->get_unavailable_reason();
			return $summary;
		}

		foreach ( $references as $reference ) {
			$result = $this->import_reference( $session, $reference );
			++$summary[ $result['status'] ];
			$summary['rewritten'] += $result['rewritten'];
		}

		if ( 0 < $summary['failed'] ) {
			$summary['blocked'] = true;
		}

		if ( 0 < $summary['imported'] || 0 < $summary['rewritten'] || 0 < $summary['skipped'] || 0 < $summary['failed'] ) {
			$summary['message'] = 'Queued media references were inspected for attachment import.';
		}

		return $summary;
	}

	/**
	 * Imports one queued media reference.
	 *
	 * @param ImportSession        $session   Session.
	 * @param ImportMediaReference $reference Media reference.
	 * @return array{status:string,rewritten:int}
	 * @throws ImportSimulatedCrashException When a controlled crash is requested.
	 */
	private function import_reference( ImportSession $session, ImportMediaReference $reference ) {
		$is_local  = $this->is_local_file_reference( $reference );
		$is_remote = $this->is_remote_url_reference( $reference );

		if ( ! $is_local && ! $is_remote ) {
			if ( $this->is_unconfirmed_candidate_reference( $session, $reference ) ) {
				return array(
					'status'    => $this->skip_reference( $session, $reference, 'Remote media reference was not confirmed as first-party by the operator decision.' ),
					'rewritten' => 0,
				);
			}

			return array(
				'status'    => 'skipped',
				'rewritten' => 0,
			);
		}

		$source_hash = $is_local ? hash_file( 'sha256', $reference->get_resolved_source_uri() ) : null;

		if ( $is_local && ( ! is_string( $source_hash ) || '' === $source_hash ) ) {
			return array(
				'status'    => $this->fail_reference( $session, $reference, 'Unable to hash media source file before import.' ),
				'rewritten' => 0,
			);
		}

		$idempotency_key = 'attachment:' . $reference->get_key();
		$record          = $this->store->find_idempotency_record( $session->get_id(), $idempotency_key );

		if ( null !== $record && ImportMediaReference::STATUS_IMPORTED === $reference->get_status() && ( null === $source_hash || $record->get_payload_hash() === $source_hash ) ) {
			return array(
				'status'    => 'skipped',
				'rewritten' => 0,
			);
		}

		$existing_attachment_id = null === $record ? null : (int) $record->get_resource_id();

		if ( null === $existing_attachment_id || $existing_attachment_id < 1 ) {
			$existing_attachment_id = $this->media->find_existing_attachment_id( $session->get_id(), $reference->get_key() );
		}

		try {
			$imported = $is_local ? $this->media->import_local_file( $reference, $existing_attachment_id ) : $this->media->import_remote_url( $reference, $existing_attachment_id );

			if ( $this->controls->should_simulate_fatal_after_media_write() ) {
				$this->store->record_event(
					$session->get_id(),
					new ImportProgressEvent(
						ImportProgressEvent::LEVEL_ERROR,
						'media.simulated_fatal_after_write',
						'Attachment write completed, then PHP exited before recording idempotency.',
						array(
							'attachment_id'   => $imported['id'],
							'reference_key'   => $reference->get_key(),
							'source_item_key' => $reference->get_source_item_key(),
							'source_hash'     => $imported['source_hash'],
						)
					)
				);

				exit( 120 );
			}

			if ( $this->controls->should_simulate_media_idempotency_crash() ) {
				$this->store->record_event(
					$session->get_id(),
					new ImportProgressEvent(
						ImportProgressEvent::LEVEL_ERROR,
						'media.simulated_crash_after_write',
						'Attachment write completed, then the importer crashed before recording idempotency.',
						array(
							'attachment_id'   => $imported['id'],
							'reference_key'   => $reference->get_key(),
							'source_item_key' => $reference->get_source_item_key(),
							'source_hash'     => $imported['source_hash'],
						)
					)
				);

				throw new ImportSimulatedCrashException( 'Simulated importer crash after attachment write and before idempotency record.' );
			}

			$this->store->remember_idempotency_record(
				$session->get_id(),
				new ImportIdempotencyRecord(
					$idempotency_key,
					$is_local ? 'attachment' : 'remote-attachment',
					(string) $imported['id'],
					$imported['source_hash']
				)
			);

			$this->store->save_media_reference( $reference->mark_imported( $imported['id'], $imported['url'], $imported['source_hash'] ) );
			$rewritten = $this->rewrite_prepared_document_reference( $session, $reference, $imported['url'], $imported['source_hash'] );

			$this->store->record_event(
				$session->get_id(),
				new ImportProgressEvent(
					ImportProgressEvent::LEVEL_INFO,
					null === $existing_attachment_id ? 'media.attachment_created' : 'media.attachment_reused',
					null === $existing_attachment_id ? 'Media reference was imported as a WordPress attachment.' : 'Existing imported attachment was reused for a media reference.',
					array(
						'attachment_id'   => $imported['id'],
						'attachment_url'  => $imported['url'],
						'reference_key'   => $reference->get_key(),
						'source_item_key' => $reference->get_source_item_key(),
					)
				)
			);

			return array(
				'status'    => 'imported',
				'rewritten' => $rewritten,
			);
		} catch ( ImportSimulatedCrashException $exception ) {
			throw $exception;
		} catch ( RuntimeException $exception ) {
			return array(
				'status'    => $this->fail_reference( $session, $reference, $exception->getMessage() ),
				'rewritten' => 0,
			);
		}
	}

	/**
	 * Marks a reference failed and records a diagnostic.
	 *
	 * @param ImportSession        $session   Session.
	 * @param ImportMediaReference $reference Media reference.
	 * @param string               $message   Failure message.
	 * @return string Summary status.
	 */
	private function fail_reference( ImportSession $session, ImportMediaReference $reference, $message ) {
		$this->store->save_media_reference( $reference->mark_failed( $message ) );
		$this->store->record_event(
			$session->get_id(),
			new ImportProgressEvent(
				ImportProgressEvent::LEVEL_ERROR,
				'media.attachment_failed',
				$message,
				array(
					'reference_key'       => $reference->get_key(),
					'source_item_key'     => $reference->get_source_item_key(),
					'resolved_source_uri' => $reference->get_resolved_source_uri(),
				)
			)
		);

		return 'failed';
	}

	/**
	 * Marks a reference skipped and records a diagnostic.
	 *
	 * @param ImportSession        $session   Session.
	 * @param ImportMediaReference $reference Media reference.
	 * @param string               $message   Skip message.
	 * @return string Summary status.
	 */
	private function skip_reference( ImportSession $session, ImportMediaReference $reference, $message ) {
		$this->store->save_media_reference( $reference->mark_skipped( $message ) );
		$this->store->record_event(
			$session->get_id(),
			new ImportProgressEvent(
				ImportProgressEvent::LEVEL_WARNING,
				'media.attachment_skipped',
				$message,
				array(
					'reference_key'       => $reference->get_key(),
					'source_item_key'     => $reference->get_source_item_key(),
					'resolved_source_uri' => $reference->get_resolved_source_uri(),
				)
			)
		);

		return 'skipped';
	}

	/**
	 * Rewrites the prepared document containing an imported media reference.
	 *
	 * @param ImportSession        $session        Session.
	 * @param ImportMediaReference $reference      Media reference.
	 * @param string               $attachment_url Attachment URL.
	 * @param string               $source_hash    Source hash.
	 * @return int Number of documents rewritten.
	 */
	private function rewrite_prepared_document_reference( ImportSession $session, ImportMediaReference $reference, $attachment_url, $source_hash ) {
		$document = $this->store->find_prepared_document( $session->get_id(), $reference->get_source_item_key() );

		if ( null === $document ) {
			return 0;
		}

		$markup    = $document->get_block_markup();
		$rewritten = str_replace( $reference->get_original_url(), (string) $attachment_url, $markup );

		if ( $rewritten === $markup ) {
			return 0;
		}

		$metadata                    = $document->get_metadata();
		$metadata['media_rewriting'] = array(
			'complete'       => true,
			'reference_key'  => $reference->get_key(),
			'attachment_url' => (string) $attachment_url,
			'source_hash'    => (string) $source_hash,
		);

		$this->store->save_prepared_document(
			$document->with_rewritten_block_markup(
				$rewritten,
				$this->rewritten_hash( $document, $rewritten, $metadata ),
				$metadata
			)
		);

		$this->store->record_event(
			$session->get_id(),
			new ImportProgressEvent(
				ImportProgressEvent::LEVEL_INFO,
				'media.reference_rewritten',
				'Prepared document media reference was rewritten to the imported attachment URL.',
				array(
					'reference_key'   => $reference->get_key(),
					'source_item_key' => $reference->get_source_item_key(),
					'attachment_url'  => (string) $attachment_url,
				)
			)
		);

		return 1;
	}

	/**
	 * Whether a reference resolves to a local readable file.
	 *
	 * @param ImportMediaReference $reference Media reference.
	 * @return bool
	 */
	private function is_local_file_reference( ImportMediaReference $reference ) {
		$uri = $reference->get_resolved_source_uri();

		return ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $uri ) && is_file( $uri ) && is_readable( $uri );
	}

	/**
	 * Whether a reference resolves to a confirmed remote URL.
	 *
	 * @param ImportMediaReference $reference Media reference.
	 * @return bool
	 */
	private function is_remote_url_reference( ImportMediaReference $reference ) {
		$metadata = $reference->get_metadata();

		if ( ! preg_match( '#^https?://#i', $reference->get_resolved_source_uri() ) || ! isset( $metadata['reference_scope'] ) ) {
			return false;
		}

		if ( 'confirmed-first-party-url' === $metadata['reference_scope'] ) {
			return true;
		}

		return ImportWxrAttachment::SCOPE_CANDIDATE_FIRST_PARTY === $metadata['reference_scope']
			&& in_array( ImportWxrAttachment::domain_for_url( $reference->get_resolved_source_uri() ), $this->get_confirmed_domains( $reference->get_session_id() ), true );
	}

	/**
	 * Whether a remote media reference was explicitly left unconfirmed.
	 *
	 * @param ImportSession        $session   Session.
	 * @param ImportMediaReference $reference Media reference.
	 * @return bool
	 */
	private function is_unconfirmed_candidate_reference( ImportSession $session, ImportMediaReference $reference ) {
		$metadata = $reference->get_metadata();

		if (
			! preg_match( '#^https?://#i', $reference->get_resolved_source_uri() ) ||
			! isset( $metadata['reference_scope'] ) ||
			ImportWxrAttachment::SCOPE_CANDIDATE_FIRST_PARTY !== $metadata['reference_scope']
		) {
			return false;
		}

		$decision = $this->store->find_decision( $session->get_id(), ImportUrlInference::DECISION_KEY );

		return null !== $decision && ImportDecision::STATUS_RESOLVED === $decision->get_status();
	}

	/**
	 * Gets confirmed first-party domains for a session id.
	 *
	 * @param ImportSessionId $session_id Session id.
	 * @return array<int,string>
	 */
	private function get_confirmed_domains( ImportSessionId $session_id ) {
		$decision = $this->store->find_decision( $session_id, ImportUrlInference::DECISION_KEY );

		if ( null === $decision || ImportDecision::STATUS_RESOLVED !== $decision->get_status() ) {
			return array();
		}

		$answer = $decision->get_answer();

		if ( null === $answer || ! isset( $answer['confirmed_domains'] ) || ! is_array( $answer['confirmed_domains'] ) ) {
			return array();
		}

		$domains = array();

		foreach ( $answer['confirmed_domains'] as $domain ) {
			$domain = strtolower( trim( (string) $domain ) );
			$domain = preg_replace( '/:\d+$/', '', $domain );

			if ( is_string( $domain ) && '' !== $domain && ! in_array( $domain, $domains, true ) ) {
				$domains[] = $domain;
			}
		}

		sort( $domains );

		return $domains;
	}

	/**
	 * Builds a stable hash for media-rewritten prepared documents.
	 *
	 * @param ImportPreparedDocument $document Prepared document.
	 * @param string                 $markup   Rewritten markup.
	 * @param array<string,mixed>    $metadata Rewritten metadata.
	 * @return string
	 */
	private function rewritten_hash( ImportPreparedDocument $document, $markup, array $metadata ) {
		return hash( 'sha256', $document->get_content_hash() . "\nmedia\n" . (string) $markup . "\n" . $this->encode_json( $metadata ) );
	}

	/**
	 * Encodes metadata deterministically enough for a content hash.
	 *
	 * @param array<string,mixed> $data Data to encode.
	 * @return string
	 */
	private function encode_json( array $data ) {
		if ( function_exists( 'wp_json_encode' ) ) {
			return (string) wp_json_encode( $data );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Unit tests run without WordPress loaded.
		return (string) json_encode( $data );
	}
}
