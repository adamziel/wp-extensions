<?php
/**
 * WXR attachment metadata persister.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

use RuntimeException;

/**
 * Applies WXR attachment captions, descriptions, alt text, and source metadata after media import.
 */
final class ImportWxrAttachmentMetadataPersister {
	const DEFAULT_REFERENCE_LIMIT = 250;

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
	 * Constructor.
	 *
	 * @param WordPressImportSessionStore      $store Durable store.
	 * @param ImportMediaGatewayInterface|null $media Optional media gateway.
	 */
	public function __construct( WordPressImportSessionStore $store, ImportMediaGatewayInterface $media = null ) {
		$this->store = $store;
		$this->media = null === $media ? new WordPressMediaGateway() : $media;
	}

	/**
	 * Advances WXR attachment metadata persistence.
	 *
	 * @param ImportSession $session Session.
	 * @param int           $limit   Maximum media references to inspect.
	 * @return array{applied:int,skipped:int,deferred:int,failed:int,message:string}
	 */
	public function advance( ImportSession $session, $limit = self::DEFAULT_REFERENCE_LIMIT ) {
		$limit   = max( 1, min( 500, (int) $limit ) );
		$summary = array(
			'applied'  => 0,
			'skipped'  => 0,
			'deferred' => 0,
			'failed'   => 0,
			'message'  => 'No imported WXR attachments had metadata ready for persistence.',
		);

		if ( ! $this->media->is_available() ) {
			$summary['message'] = $this->media->get_unavailable_reason();
			return $summary;
		}

		$after_reference_key  = null;
		$processed_references = 0;

		do {
			$references      = $this->store->list_media_references_by_statuses_after_reference_key(
				$session->get_id(),
				array( ImportMediaReference::STATUS_IMPORTED ),
				$after_reference_key,
				$limit
			);
			$reference_count = count( $references );

			foreach ( $references as $reference ) {
				$after_reference_key = $reference->get_key();

				if ( ! $this->is_wxr_attachment( $reference ) ) {
					continue;
				}

				$result = $this->persist_reference_metadata( $session, $reference );
				++$summary[ $result ];

				if ( 'skipped' === $result ) {
					continue;
				}

				++$processed_references;

				if ( $processed_references >= $limit ) {
					break 2;
				}
			}
		} while ( $reference_count === $limit );

		if ( 0 < $summary['applied'] || 0 < $summary['skipped'] || 0 < $summary['deferred'] || 0 < $summary['failed'] ) {
			$summary['message'] = 'Imported WXR attachments were inspected for caption, alt text, and source metadata persistence.';
		}

		return $summary;
	}

	/**
	 * Applies metadata for one imported WXR attachment reference.
	 *
	 * @param ImportSession        $session   Session.
	 * @param ImportMediaReference $reference Imported media reference.
	 * @return string Summary bucket.
	 */
	private function persist_reference_metadata( ImportSession $session, ImportMediaReference $reference ) {
		$reference_metadata  = $reference->get_metadata();
		$attachment_id       = isset( $reference_metadata['attachment_id'] ) ? (int) $reference_metadata['attachment_id'] : 0;
		$attachment_metadata = ImportWxrAttachment::metadata_from_reference( $reference );

		if ( $attachment_id < 1 ) {
			$this->record_event(
				$session,
				ImportProgressEvent::LEVEL_WARNING,
				'attachment_metadata.deferred',
				'WXR attachment metadata is staged, but the imported attachment id is not available yet.',
				$reference,
				array()
			);
			return 'deferred';
		}

		if ( empty( $attachment_metadata ) ) {
			return 'skipped';
		}

		$payload_hash = $this->payload_hash( $attachment_id, $attachment_metadata );
		$record       = $this->store->find_idempotency_record( $session->get_id(), 'attachment-metadata:' . $reference->get_key() );

		if ( null !== $record && $record->get_payload_hash() === $payload_hash ) {
			return 'skipped';
		}

		try {
			$this->media->apply_attachment_metadata( $attachment_id, $reference );
			$this->store->remember_idempotency_record(
				$session->get_id(),
				new ImportIdempotencyRecord(
					'attachment-metadata:' . $reference->get_key(),
					'attachment-metadata',
					(string) $attachment_id,
					$payload_hash
				)
			);
			$this->record_event(
				$session,
				ImportProgressEvent::LEVEL_INFO,
				'attachment_metadata.applied',
				'WXR attachment caption, alt text, and source metadata were applied to the imported attachment.',
				$reference,
				array(
					'attachment_id' => $attachment_id,
					'metadata_keys' => array_keys( $attachment_metadata ),
				)
			);

			return 'applied';
		} catch ( RuntimeException $exception ) {
			$this->record_event(
				$session,
				ImportProgressEvent::LEVEL_ERROR,
				'attachment_metadata.failed',
				$exception->getMessage(),
				$reference,
				array( 'attachment_id' => $attachment_id )
			);

			return 'failed';
		}
	}

	/**
	 * Whether a media reference is a WXR attachment.
	 *
	 * @param ImportMediaReference $reference Media reference.
	 * @return bool
	 */
	private function is_wxr_attachment( ImportMediaReference $reference ) {
		$metadata = $reference->get_metadata();

		return isset( $metadata['source'], $metadata['wxr_attachment_id'] ) && 'wxr' === (string) $metadata['source'];
	}

	/**
	 * Builds an idempotency hash for applied metadata.
	 *
	 * @param int                 $attachment_id       Attachment id.
	 * @param array<string,mixed> $attachment_metadata Attachment metadata.
	 * @return string
	 */
	private function payload_hash( $attachment_id, array $attachment_metadata ) {
		return hash( 'sha256', (string) (int) $attachment_id . "\n" . $this->encode_json( $attachment_metadata ) );
	}

	/**
	 * Encodes data for stable-enough idempotency hashing.
	 *
	 * @param array<string,mixed> $data Data.
	 * @return string
	 */
	private function encode_json( array $data ) {
		if ( function_exists( 'wp_json_encode' ) ) {
			return (string) wp_json_encode( $data );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Unit tests run without WordPress loaded.
		return (string) json_encode( $data );
	}

	/**
	 * Records an attachment metadata progress event.
	 *
	 * @param ImportSession        $session   Session.
	 * @param string               $level     Event level.
	 * @param string               $type      Event type.
	 * @param string               $message   Event message.
	 * @param ImportMediaReference $reference Media reference.
	 * @param array<string,mixed>  $extra     Extra event context.
	 * @return void
	 */
	private function record_event( ImportSession $session, $level, $type, $message, ImportMediaReference $reference, array $extra ) {
		$this->store->record_event(
			$session->get_id(),
			new ImportProgressEvent(
				$level,
				$type,
				$message,
				array_merge(
					array(
						'reference_key'     => $reference->get_key(),
						'source_item_key'   => $reference->get_source_item_key(),
						'wxr_attachment_id' => isset( $reference->get_metadata()['wxr_attachment_id'] ) ? $reference->get_metadata()['wxr_attachment_id'] : null,
					),
					$extra
				)
			)
		);
	}
}
