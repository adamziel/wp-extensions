<?php
/**
 * Prepared WXR postmeta persister.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

use RuntimeException;

/**
 * Persists WXR postmeta after the matching prepared document has a local draft.
 */
final class ImportPostMetaPersister {
	const DEFAULT_DOCUMENT_LIMIT = 100;

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
	 * Local public site URL for first-party URL rewriting.
	 *
	 * @var string
	 */
	private $local_site_url;

	/**
	 * Constructor.
	 *
	 * @param WordPressImportSessionStore     $store Durable store.
	 * @param ImportPostGatewayInterface|null $posts Optional post gateway.
	 * @param string|null                     $local_site_url Optional local public site URL.
	 */
	public function __construct( WordPressImportSessionStore $store, ImportPostGatewayInterface $posts = null, $local_site_url = null ) {
		$this->store          = $store;
		$this->posts          = null === $posts ? new WordPressPostGateway() : $posts;
		$this->local_site_url = $this->normalize_local_site_url( $local_site_url );
	}

	/**
	 * Advances WXR postmeta persistence for prepared documents.
	 *
	 * @param ImportSession $session Session.
	 * @param int           $limit   Maximum documents to inspect.
	 * @return array{applied:int,skipped:int,deferred:int,failed:int,message:string}
	 */
	public function advance( ImportSession $session, $limit = self::DEFAULT_DOCUMENT_LIMIT ) {
		$limit   = max( 1, min( 250, (int) $limit ) );
		$summary = array(
			'applied'  => 0,
			'skipped'  => 0,
			'deferred' => 0,
			'failed'   => 0,
			'message'  => 'No staged WXR postmeta was ready for persistence.',
		);

		if ( ! $this->posts->is_available() ) {
			$summary['message'] = $this->posts->get_unavailable_reason();
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

				if ( empty( ImportWxrPostMeta::entries_from_document( $document ) ) ) {
					continue;
				}

				$result = $this->persist_document_meta( $session, $document );
				++$summary[ $result ];

				if ( 'skipped' === $result ) {
					continue;
				}

				++$processed_documents;

				if ( $processed_documents >= $limit ) {
					break 2;
				}
			}
		} while ( $document_count === $limit );

		if ( 0 < $summary['applied'] || 0 < $summary['skipped'] || 0 < $summary['deferred'] || 0 < $summary['failed'] ) {
			$summary['message'] = 'WXR postmeta persistence inspected staged documents.';
		}

		return $summary;
	}

	/**
	 * Persists WXR postmeta for one prepared document.
	 *
	 * @param ImportSession          $session  Session.
	 * @param ImportPreparedDocument $document Prepared document.
	 * @return string Summary bucket.
	 */
	private function persist_document_meta( ImportSession $session, ImportPreparedDocument $document ) {
		$idempotency_key = 'postmeta:' . $document->get_source_item_key();
		$record          = $this->store->find_idempotency_record( $session->get_id(), $idempotency_key );

		$post_id = $this->local_post_id( $session, $document );

		if ( null === $post_id ) {
			$this->record_event(
				$session,
				ImportProgressEvent::LEVEL_WARNING,
				'postmeta.deferred',
				'WXR postmeta is staged, but the local draft post is not available yet.',
				$document,
				array()
			);
			return 'deferred';
		}

		$remapped          = $this->remap_attachment_references( $session, $document );
		$post_remapped     = $this->remap_wxr_post_references( $session, $remapped['document'] );
		$url_remapped      = $this->remap_first_party_urls( $session, $post_remapped['document'] );
		$document          = $url_remapped['document'];
		$payload_hash      = ImportWxrPostMeta::payload_hash( $document );
		$should_apply_meta = null === $record || $record->get_payload_hash() !== $payload_hash;
		$thumbnail_result  = $this->apply_featured_media( $session, $document, $post_id );
		$deferred_ids      = array_values( array_unique( array_merge( $remapped['deferred_ids'], $post_remapped['deferred_ids'] ) ) );

		if ( ! empty( $deferred_ids ) || ! empty( $remapped['deferred_urls'] ) || ! empty( $post_remapped['deferred_post_ids'] ) ) {
			$this->record_event(
				$session,
				ImportProgressEvent::LEVEL_WARNING,
				'postmeta.deferred_references',
				'WXR postmeta contains WXR attachment or post/page references that are still waiting for import before they can be safely remapped.',
				$document,
				array(
					'post_id'           => $post_id,
					'deferred_ids'      => $deferred_ids,
					'deferred_urls'     => $remapped['deferred_urls'],
					'deferred_post_ids' => $post_remapped['deferred_post_ids'],
				)
			);
			return 'deferred';
		}

		if ( ! $should_apply_meta ) {
			return $thumbnail_result;
		}

		try {
			$result = $this->posts->apply_post_meta( $post_id, $document );

			$this->store->remember_idempotency_record(
				$session->get_id(),
				new ImportIdempotencyRecord(
					$idempotency_key,
					'postmeta',
					(string) $post_id,
					$payload_hash
				)
			);
			$this->record_event(
				$session,
				ImportProgressEvent::LEVEL_INFO,
				'postmeta.applied',
				'Staged WXR postmeta was applied to the imported draft post.',
				$document,
				array(
					'post_id'       => $post_id,
					'applied'       => (int) $result['applied'],
					'skipped'       => (int) $result['skipped'],
					'skipped_keys'  => $result['skipped_keys'],
					'remapped'      => (int) $remapped['remapped'] + (int) $post_remapped['remapped'] + (int) $url_remapped['rewritten'],
					'url_rewritten' => (int) $url_remapped['rewritten'],
				)
			);

			return 'applied' === $thumbnail_result || 'skipped' === $thumbnail_result ? 'applied' : $thumbnail_result;
		} catch ( RuntimeException $exception ) {
			$this->record_event(
				$session,
				ImportProgressEvent::LEVEL_ERROR,
				'postmeta.failed',
				$exception->getMessage(),
				$document,
				array( 'post_id' => $post_id )
			);

			return 'failed';
		}
	}

	/**
	 * Remaps WXR attachment ids and source URLs inside staged postmeta values.
	 *
	 * @param ImportSession          $session  Session.
	 * @param ImportPreparedDocument $document Prepared document.
	 * @return array{document:ImportPreparedDocument,remapped:int,deferred_ids:array<int,string>,deferred_urls:array<int,string>}
	 */
	private function remap_attachment_references( ImportSession $session, ImportPreparedDocument $document ) {
		$source_item_key         = ImportWxrAttachment::source_item_key_from_document( $document );
		$attachment_id_map       = array();
		$attachment_url_map      = array();
		$pending_attachment_ids  = array();
		$pending_attachment_urls = array();
		$attachment_ref_statuses = array(
			ImportMediaReference::STATUS_QUEUED,
			ImportMediaReference::STATUS_IMPORTED,
		);

		$after_reference_key = null;
		$limit               = 500;

		do {
			$references      = $this->store->list_media_references_by_statuses_after_reference_key(
				$session->get_id(),
				$attachment_ref_statuses,
				$after_reference_key,
				$limit
			);
			$reference_count = count( $references );

			foreach ( $references as $reference ) {
				$after_reference_key = $reference->get_key();

				if ( ImportWxrAttachment::source_item_key_from_reference( $reference ) !== $source_item_key ) {
					continue;
				}

				$metadata = $reference->get_metadata();

				if ( ! isset( $metadata['source'], $metadata['wxr_attachment_id'] ) || 'wxr' !== (string) $metadata['source'] ) {
					continue;
				}

				$remote_attachment_id = (string) (int) $metadata['wxr_attachment_id'];
				$source_urls          = $this->reference_source_urls( $reference );

				if ( ImportMediaReference::STATUS_IMPORTED !== $reference->get_status() ) {
					$pending_attachment_ids[ $remote_attachment_id ] = true;

					foreach ( $source_urls as $source_url ) {
						$pending_attachment_urls[ $source_url ] = true;
					}

					continue;
				}

				$attachment_id  = isset( $metadata['attachment_id'] ) ? (int) $metadata['attachment_id'] : 0;
				$attachment_url = isset( $metadata['attachment_url'] ) ? (string) $metadata['attachment_url'] : '';

				if ( $attachment_id > 0 ) {
					$attachment_id_map[ $remote_attachment_id ] = $attachment_id;
				} else {
					$pending_attachment_ids[ $remote_attachment_id ] = true;
				}

				if ( '' !== $attachment_url ) {
					foreach ( $source_urls as $source_url ) {
						$attachment_url_map[ $source_url ] = $attachment_url;
					}
				} else {
					foreach ( $source_urls as $source_url ) {
						$pending_attachment_urls[ $source_url ] = true;
					}
				}
			}
		} while ( $reference_count === $limit );

		return ImportWxrPostMeta::remap_document_attachment_references(
			$document,
			$attachment_id_map,
			$attachment_url_map,
			$pending_attachment_ids,
			$pending_attachment_urls
		);
	}

	/**
	 * Returns source URLs associated with one WXR attachment reference.
	 *
	 * @param ImportMediaReference $reference Media reference.
	 * @return array<int,string>
	 */
	private function reference_source_urls( ImportMediaReference $reference ) {
		$metadata = $reference->get_metadata();
		$urls     = array(
			$reference->get_original_url(),
			$reference->get_resolved_source_uri(),
		);

		foreach ( array( 'wxr_guid', 'attachment_url' ) as $metadata_key ) {
			if ( isset( $metadata[ $metadata_key ] ) ) {
				$urls[] = (string) $metadata[ $metadata_key ];
			}
		}

		$normalized = array();

		foreach ( $urls as $url ) {
			$url = trim( (string) $url );

			if ( '' !== $url && ! in_array( $url, $normalized, true ) ) {
				$normalized[] = $url;
			}
		}

		return $normalized;
	}

	/**
	 * Remaps WXR post/page ids inside staged postmeta values.
	 *
	 * @param ImportSession          $session  Session.
	 * @param ImportPreparedDocument $document Prepared document.
	 * @return array{document:ImportPreparedDocument,remapped:int,deferred_ids:array<int,string>,deferred_post_ids:array<int,string>}
	 */
	private function remap_wxr_post_references( ImportSession $session, ImportPreparedDocument $document ) {
		$source_item_key       = ImportWxrAttachment::source_item_key_from_document( $document );
		$post_id_map           = array();
		$pending_post_ids      = array();
		$referenced_remote_ids = ImportWxrPostMeta::post_reference_ids_from_document( $document );

		foreach ( $referenced_remote_ids as $remote_post_id ) {
			$remote_post_id = (string) (int) $remote_post_id;

			if ( '' === $remote_post_id || '0' === $remote_post_id ) {
				continue;
			}

			$local_post_id = $this->local_wxr_post_id( $session, $source_item_key, $remote_post_id );

			if ( null !== $local_post_id ) {
				$post_id_map[ $remote_post_id ] = $local_post_id;
				continue;
			}

			if ( $this->wxr_post_reference_may_still_import( $session, $source_item_key, $remote_post_id ) ) {
				$pending_post_ids[ $remote_post_id ] = true;
			}
		}

		return ImportWxrPostMeta::remap_document_post_references( $document, $post_id_map, $pending_post_ids );
	}

	/**
	 * Remaps confirmed first-party URLs inside WXR postmeta values.
	 *
	 * @param ImportSession          $session  Session.
	 * @param ImportPreparedDocument $document Prepared document.
	 * @return array{document:ImportPreparedDocument,rewritten:int}
	 */
	private function remap_first_party_urls( ImportSession $session, ImportPreparedDocument $document ) {
		return ImportWxrPostMeta::remap_document_first_party_urls(
			$document,
			$this->confirmed_domains( $session ),
			$this->local_site_url
		);
	}

	/**
	 * Returns confirmed first-party domains from the resolved session decision.
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
			$domain = $this->normalize_domain( $domain );

			if ( '' !== $domain && ! in_array( $domain, $domains, true ) ) {
				$domains[] = $domain;
			}
		}

		sort( $domains );

		return $domains;
	}

	/**
	 * Finds the local WordPress post id for a remote WXR post id.
	 *
	 * @param ImportSession $session         Session.
	 * @param string        $source_item_key WXR source item key.
	 * @param string        $remote_post_id  Remote WXR post id.
	 * @return int|null
	 */
	private function local_wxr_post_id( ImportSession $session, $source_item_key, $remote_post_id ) {
		$document_key = (string) $source_item_key . ':wxr-post:' . (string) (int) $remote_post_id;
		$record       = $this->store->find_idempotency_record( $session->get_id(), 'post:' . $document_key );

		if ( null !== $record && (int) $record->get_resource_id() > 0 ) {
			return (int) $record->get_resource_id();
		}

		$post_id = $this->posts->find_existing_post_id( $session->get_id(), $document_key );

		return null === $post_id || (int) $post_id < 1 ? null : (int) $post_id;
	}

	/**
	 * Whether a WXR post reference should wait for a future imported draft.
	 *
	 * @param ImportSession $session         Session.
	 * @param string        $source_item_key WXR source item key.
	 * @param string        $remote_post_id  Remote WXR post id.
	 * @return bool
	 */
	private function wxr_post_reference_may_still_import( ImportSession $session, $source_item_key, $remote_post_id ) {
		$document_key = (string) $source_item_key . ':wxr-post:' . (string) (int) $remote_post_id;

		if ( null !== $this->store->find_prepared_document( $session->get_id(), $document_key ) ) {
			return true;
		}

		$source_item = $this->store->find_source_item( $session->get_id(), $source_item_key );

		if ( null === $source_item ) {
			return false;
		}

		return ! in_array(
			$source_item->get_status(),
			array(
				ImportSourceItem::STATUS_IMPORTED,
				ImportSourceItem::STATUS_SKIPPED,
				ImportSourceItem::STATUS_FAILED,
			),
			true
		);
	}

	/**
	 * Applies WXR `_thumbnail_id` once the referenced WXR attachment has a local id.
	 *
	 * @param ImportSession          $session  Session.
	 * @param ImportPreparedDocument $document Prepared document.
	 * @param int                    $post_id  Local post id.
	 * @return string Summary bucket.
	 */
	private function apply_featured_media( ImportSession $session, ImportPreparedDocument $document, $post_id ) {
		$remote_attachment_id = ImportWxrAttachment::thumbnail_remote_id_from_document( $document );

		if ( null === $remote_attachment_id ) {
			return 'skipped';
		}

		$source_item_key = ImportWxrAttachment::source_item_key_from_document( $document );
		$reference_key   = ImportWxrAttachment::reference_key( $source_item_key, $remote_attachment_id );
		$reference       = $this->store->find_media_reference( $session->get_id(), $reference_key );

		if ( null === $reference || ImportMediaReference::STATUS_IMPORTED !== $reference->get_status() ) {
			$this->record_event(
				$session,
				ImportProgressEvent::LEVEL_WARNING,
				'thumbnail.deferred',
				'WXR featured image metadata is staged, but the referenced attachment has not been imported yet.',
				$document,
				array(
					'post_id'                  => (int) $post_id,
					'remote_attachment_id'     => $remote_attachment_id,
					'attachment_reference_key' => $reference_key,
				)
			);
			return 'deferred';
		}

		$reference_metadata = $reference->get_metadata();
		$attachment_id      = isset( $reference_metadata['attachment_id'] ) ? (int) $reference_metadata['attachment_id'] : 0;

		if ( $attachment_id < 1 ) {
			$this->record_event(
				$session,
				ImportProgressEvent::LEVEL_WARNING,
				'thumbnail.deferred',
				'WXR attachment import finished without a usable local attachment id for featured image remapping.',
				$document,
				array(
					'post_id'                  => (int) $post_id,
					'remote_attachment_id'     => $remote_attachment_id,
					'attachment_reference_key' => $reference_key,
				)
			);
			return 'deferred';
		}

		$idempotency_key = 'thumbnail:' . $document->get_source_item_key();
		$payload_hash    = hash( 'sha256', (string) $remote_attachment_id . ':' . (string) $attachment_id );
		$record          = $this->store->find_idempotency_record( $session->get_id(), $idempotency_key );

		if ( null !== $record && $record->get_payload_hash() === $payload_hash && (int) $record->get_resource_id() === $attachment_id ) {
			return 'skipped';
		}

		try {
			$this->posts->apply_featured_media( $post_id, $attachment_id, $document );
			$this->store->remember_idempotency_record(
				$session->get_id(),
				new ImportIdempotencyRecord(
					$idempotency_key,
					'thumbnail',
					(string) $attachment_id,
					$payload_hash
				)
			);
			$this->record_event(
				$session,
				ImportProgressEvent::LEVEL_INFO,
				'thumbnail.applied',
				'WXR featured image metadata was remapped to the imported local attachment.',
				$document,
				array(
					'post_id'              => (int) $post_id,
					'attachment_id'        => $attachment_id,
					'remote_attachment_id' => $remote_attachment_id,
				)
			);
			return 'applied';
		} catch ( RuntimeException $exception ) {
			$this->record_event(
				$session,
				ImportProgressEvent::LEVEL_ERROR,
				'thumbnail.failed',
				$exception->getMessage(),
				$document,
				array(
					'post_id'              => (int) $post_id,
					'remote_attachment_id' => $remote_attachment_id,
					'attachment_id'        => $attachment_id,
				)
			);
			return 'failed';
		}
	}

	/**
	 * Finds the local post id for a prepared document.
	 *
	 * @param ImportSession          $session  Session.
	 * @param ImportPreparedDocument $document Prepared document.
	 * @return int|null
	 */
	private function local_post_id( ImportSession $session, ImportPreparedDocument $document ) {
		$record = $this->store->find_idempotency_record( $session->get_id(), 'post:' . $document->get_source_item_key() );

		if ( null !== $record && (int) $record->get_resource_id() > 0 ) {
			return (int) $record->get_resource_id();
		}

		$post_id = $this->posts->find_existing_post_id( $session->get_id(), $document->get_source_item_key() );

		return null === $post_id || (int) $post_id < 1 ? null : (int) $post_id;
	}

	/**
	 * Records a WXR postmeta progress event.
	 *
	 * @param ImportSession          $session  Session.
	 * @param string                 $level    Event level.
	 * @param string                 $type     Event type.
	 * @param string                 $message  Event message.
	 * @param ImportPreparedDocument $document Prepared document.
	 * @param array<string,mixed>    $extra    Extra context.
	 * @return void
	 */
	private function record_event( ImportSession $session, $level, $type, $message, ImportPreparedDocument $document, array $extra ) {
		$this->store->record_event(
			$session->get_id(),
			new ImportProgressEvent(
				$level,
				$type,
				$message,
				array_merge(
					array(
						'source_item_key' => $document->get_source_item_key(),
						'meta_count'      => count( ImportWxrPostMeta::entries_from_document( $document ) ),
					),
					$extra
				)
			)
		);
	}

	/**
	 * Normalizes a URL host for exact-domain comparison.
	 *
	 * @param string $domain Raw domain.
	 * @return string
	 */
	private function normalize_domain( $domain ) {
		$domain = strtolower( trim( (string) $domain ) );
		$domain = preg_replace( '/:\d+$/', '', $domain );

		return is_string( $domain ) ? $domain : '';
	}

	/**
	 * Normalizes the local public site URL.
	 *
	 * @param string|null $local_site_url Optional local public site URL.
	 * @return string
	 */
	private function normalize_local_site_url( $local_site_url ) {
		if ( null === $local_site_url && function_exists( 'home_url' ) ) {
			$local_site_url = home_url( '/' );
		}

		$local_site_url = trim( (string) $local_site_url );

		if ( '' === $local_site_url ) {
			$local_site_url = 'http://example.org/';
		}

		return rtrim( $local_site_url, '/' ) . '/';
	}
}
