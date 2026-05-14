<?php
/**
 * WXR attachment parent relationship persister.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

use RuntimeException;

/**
 * Restores WXR attachment post_parent relationships after media and parent posts exist locally.
 */
final class ImportWxrAttachmentParentPersister {
	const DEFAULT_REFERENCE_LIMIT = 250;

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
	 * WordPress media gateway.
	 *
	 * @var ImportMediaGatewayInterface
	 */
	private $media;

	/**
	 * Constructor.
	 *
	 * @param WordPressImportSessionStore      $store Durable store.
	 * @param ImportPostGatewayInterface|null  $posts Optional post gateway.
	 * @param ImportMediaGatewayInterface|null $media Optional media gateway.
	 */
	public function __construct( WordPressImportSessionStore $store, ImportPostGatewayInterface $posts = null, ImportMediaGatewayInterface $media = null ) {
		$this->store = $store;
		$this->posts = null === $posts ? new WordPressPostGateway() : $posts;
		$this->media = null === $media ? new WordPressMediaGateway() : $media;
	}

	/**
	 * Advances WXR attachment parent restoration.
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
			'message'  => 'No imported WXR attachments were ready for parent restoration.',
		);

		if ( ! $this->media->is_available() ) {
			$summary['message'] = $this->media->get_unavailable_reason();
			return $summary;
		}

		if ( ! $this->posts->is_available() ) {
			$summary['message'] = $this->posts->get_unavailable_reason();
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

				if ( ! $this->has_wxr_parent( $reference ) ) {
					continue;
				}

				$result = $this->persist_reference_parent( $session, $reference );
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
			$summary['message'] = 'Imported WXR attachments were inspected for parent restoration.';
		}

		return $summary;
	}

	/**
	 * Persists one imported attachment parent relationship.
	 *
	 * @param ImportSession        $session   Session.
	 * @param ImportMediaReference $reference Imported media reference.
	 * @return string Summary bucket.
	 */
	private function persist_reference_parent( ImportSession $session, ImportMediaReference $reference ) {
		$metadata           = $reference->get_metadata();
		$remote_parent_id   = (int) $metadata['wxr_post_parent'];
		$attachment_id      = isset( $metadata['attachment_id'] ) ? (int) $metadata['attachment_id'] : 0;
		$parent_item_key    = ImportWxrAttachment::parent_document_source_item_key( $reference );
		$local_parent_id    = null === $parent_item_key ? null : $this->local_post_id( $session, $parent_item_key );
		$idempotency_key    = 'attachment-parent:' . $reference->get_key();
		$payload_hash       = hash( 'sha256', (string) $attachment_id . ':' . (string) $remote_parent_id . ':' . (string) $local_parent_id );
		$idempotency_record = $this->store->find_idempotency_record( $session->get_id(), $idempotency_key );

		if ( null !== $idempotency_record && $idempotency_record->get_payload_hash() === $payload_hash ) {
			return 'skipped';
		}

		if ( $attachment_id < 1 ) {
			$this->record_event(
				$session,
				ImportProgressEvent::LEVEL_WARNING,
				'attachment_parent.deferred',
				'WXR attachment parent is staged, but the imported attachment id is not available yet.',
				$reference,
				array(
					'remote_parent_id' => $remote_parent_id,
					'parent_item_key'  => $parent_item_key,
				)
			);
			return 'deferred';
		}

		if ( null === $local_parent_id || $local_parent_id < 1 ) {
			$this->record_event(
				$session,
				ImportProgressEvent::LEVEL_WARNING,
				'attachment_parent.deferred',
				'WXR attachment parent is staged, but the referenced parent post has not been imported yet.',
				$reference,
				array(
					'attachment_id'    => $attachment_id,
					'remote_parent_id' => $remote_parent_id,
					'parent_item_key'  => $parent_item_key,
				)
			);
			return 'deferred';
		}

		try {
			$this->media->apply_attachment_parent( $attachment_id, $local_parent_id, $reference );
			$this->store->remember_idempotency_record(
				$session->get_id(),
				new ImportIdempotencyRecord(
					$idempotency_key,
					'attachment-parent',
					(string) $attachment_id,
					$payload_hash
				)
			);
			$this->record_event(
				$session,
				ImportProgressEvent::LEVEL_INFO,
				'attachment_parent.applied',
				'WXR attachment parent was remapped to the imported local parent post.',
				$reference,
				array(
					'attachment_id'    => $attachment_id,
					'local_parent_id'  => $local_parent_id,
					'remote_parent_id' => $remote_parent_id,
					'parent_item_key'  => $parent_item_key,
				)
			);

			return 'applied';
		} catch ( RuntimeException $exception ) {
			$this->record_event(
				$session,
				ImportProgressEvent::LEVEL_ERROR,
				'attachment_parent.failed',
				$exception->getMessage(),
				$reference,
				array(
					'attachment_id'    => $attachment_id,
					'remote_parent_id' => $remote_parent_id,
					'parent_item_key'  => $parent_item_key,
				)
			);

			return 'failed';
		}
	}

	/**
	 * Whether a media reference represents a WXR attachment with a remote parent id.
	 *
	 * @param ImportMediaReference $reference Media reference.
	 * @return bool
	 */
	private function has_wxr_parent( ImportMediaReference $reference ) {
		$metadata = $reference->get_metadata();

		return isset( $metadata['source'], $metadata['wxr_post_parent'] )
			&& 'wxr' === (string) $metadata['source']
			&& (int) $metadata['wxr_post_parent'] > 0;
	}

	/**
	 * Finds the local post id for a prepared document source key.
	 *
	 * @param ImportSession $session         Session.
	 * @param string        $source_item_key Prepared document source item key.
	 * @return int|null
	 */
	private function local_post_id( ImportSession $session, $source_item_key ) {
		$record = $this->store->find_idempotency_record( $session->get_id(), 'post:' . $source_item_key );

		if ( null !== $record && (int) $record->get_resource_id() > 0 ) {
			return (int) $record->get_resource_id();
		}

		$post_id = $this->posts->find_existing_post_id( $session->get_id(), $source_item_key );

		return null === $post_id || (int) $post_id < 1 ? null : (int) $post_id;
	}

	/**
	 * Records an attachment parent progress event.
	 *
	 * @param ImportSession        $session   Session.
	 * @param string               $level     Event level.
	 * @param string               $type      Event type.
	 * @param string               $message   Event message.
	 * @param ImportMediaReference $reference Media reference.
	 * @param array<string,mixed>  $extra     Extra context.
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
						'reference_key'   => $reference->get_key(),
						'source_item_key' => $reference->get_source_item_key(),
					),
					$extra
				)
			)
		);
	}
}
