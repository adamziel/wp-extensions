<?php
/**
 * EPUB internal link resolver.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

/**
 * Rewrites staged EPUB spine anchor placeholders to imported draft permalinks.
 */
final class ImportEpubInternalLinkResolver {
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
	 * Constructor.
	 *
	 * @param WordPressImportSessionStore     $store Durable store.
	 * @param ImportPostGatewayInterface|null $posts Optional post gateway.
	 */
	public function __construct( WordPressImportSessionStore $store, ImportPostGatewayInterface $posts = null ) {
		$this->store = $store;
		$this->posts = null === $posts ? new WordPressPostGateway() : $posts;
	}

	/**
	 * Advances EPUB internal link resolution for prepared documents.
	 *
	 * @param ImportSession $session Session.
	 * @param int           $limit   Maximum documents to inspect.
	 * @return array{resolved:int,deferred:int,skipped:int,failed:int,message:string}
	 */
	public function advance( ImportSession $session, $limit = self::DEFAULT_DOCUMENT_LIMIT ) {
		$limit   = max( 1, min( 250, (int) $limit ) );
		$summary = array(
			'resolved' => 0,
			'deferred' => 0,
			'skipped'  => 0,
			'failed'   => 0,
			'message'  => 'No EPUB internal links were ready for permalink resolution.',
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
				$result                = $this->resolve_document_links( $session, $document );

				if ( 'skipped' === $result ) {
					++$summary['skipped'];
					continue;
				}

				++$summary[ $result ];
				++$processed_documents;

				if ( $processed_documents >= $limit ) {
					break 2;
				}
			}
		} while ( $document_count === $limit );

		if ( 0 < $summary['resolved'] || 0 < $summary['deferred'] || 0 < $summary['failed'] ) {
			$summary['message'] = 'EPUB internal link resolution inspected staged documents.';
		}

		return $summary;
	}

	/**
	 * Resolves links for one prepared EPUB document.
	 *
	 * @param ImportSession          $session  Session.
	 * @param ImportPreparedDocument $document Prepared document.
	 * @return string Summary bucket.
	 */
	private function resolve_document_links( ImportSession $session, ImportPreparedDocument $document ) {
		$metadata = $document->get_metadata();

		if ( 'epub' !== $document->get_format() || empty( $metadata['epub_internal_links'] ) || ! is_array( $metadata['epub_internal_links'] ) ) {
			return 'skipped';
		}

		$unresolved = $this->pending_links( $metadata['epub_internal_links'] );

		if ( empty( $unresolved ) ) {
			return 'skipped';
		}

		$block_markup    = $document->get_block_markup();
		$resolved_links  = isset( $metadata['epub_internal_links_resolved'] ) && is_array( $metadata['epub_internal_links_resolved'] ) ? $metadata['epub_internal_links_resolved'] : array();
		$deferred_links  = array();
		$rewritten_count = 0;

		foreach ( $unresolved as $index => $link ) {
			$target_key = $this->target_document_key( $document, $link );

			if ( null === $target_key ) {
				$deferred_links[] = $link;
				continue;
			}

			$record = $this->store->find_idempotency_record( $session->get_id(), 'post:' . $target_key );

			if ( null === $record || 'post' !== $record->get_resource_type() ) {
				$deferred_links[] = $link;
				continue;
			}

			$permalink = $this->posts->get_permalink( (int) $record->get_resource_id() );

			if ( null === $permalink ) {
				$deferred_links[] = $link;
				continue;
			}

			$resolved_href = $this->resolved_href( $permalink, $link );
			$next_markup   = $this->replace_href( $block_markup, (string) $link['rewritten_href'], $resolved_href );

			if ( $next_markup === $block_markup ) {
				$deferred_links[] = $link;
				continue;
			}

			$block_markup                  = $next_markup;
			$link['resolved_href']         = $resolved_href;
			$link['resolved_post_id']      = (int) $record->get_resource_id();
			$link['resolved_at_unix_time'] = time();
			$resolved_links[]              = $link;
			++$rewritten_count;
		}

		if ( 0 === $rewritten_count ) {
			$this->record_deferred_event( $session, $document, $deferred_links );
			return empty( $deferred_links ) ? 'skipped' : 'deferred';
		}

		$metadata['epub_internal_links']          = array_values( $deferred_links );
		$metadata['epub_internal_links_resolved'] = array_values( $resolved_links );
		$metadata['epub_internal_links_status']   = empty( $deferred_links ) ? 'resolved' : 'partial';

		$content_hash = hash( 'sha256', 'epub-link-resolved' . "\n" . $document->get_source_item_key() . "\n" . $block_markup );
		$this->store->save_prepared_document( $document->with_rewritten_block_markup( $block_markup, $content_hash, $metadata ) );

		$this->store->record_event(
			$session->get_id(),
			new ImportProgressEvent(
				ImportProgressEvent::LEVEL_INFO,
				'epub.internal_links_resolved',
				'EPUB internal links were resolved to imported draft permalinks.',
				array(
					'source_item_key' => $document->get_source_item_key(),
					'resolved'        => $rewritten_count,
					'deferred'        => count( $deferred_links ),
				)
			)
		);

		return 'resolved';
	}

	/**
	 * Returns unresolved link records.
	 *
	 * @param array<int,array<string,mixed>> $links Link metadata.
	 * @return array<int,array<string,mixed>>
	 */
	private function pending_links( array $links ) {
		$pending = array();

		foreach ( $links as $index => $link ) {
			if ( is_array( $link ) && empty( $link['resolved_href'] ) && ! empty( $link['rewritten_href'] ) ) {
				$pending[ $index ] = $link;
			}
		}

		return $pending;
	}

	/**
	 * Builds the prepared document key for a target EPUB spine entry.
	 *
	 * @param ImportPreparedDocument $document Source document.
	 * @param array<string,mixed>    $link     Link metadata.
	 * @return string|null
	 */
	private function target_document_key( ImportPreparedDocument $document, array $link ) {
		if ( ! isset( $link['epub_target_spine_index'] ) ) {
			return null;
		}

		$source_key = $document->get_source_item_key();
		$pos        = strrpos( $source_key, ':epub-spine:' );

		if ( false === $pos ) {
			return null;
		}

		return substr( $source_key, 0, $pos ) . ':epub-spine:' . (int) $link['epub_target_spine_index'];
	}

	/**
	 * Builds the final href from a post permalink and original EPUB fragment.
	 *
	 * @param string              $permalink Imported post permalink.
	 * @param array<string,mixed> $link      Link metadata.
	 * @return string
	 */
	private function resolved_href( $permalink, array $link ) {
		$fragment = isset( $link['target_fragment'] ) ? trim( (string) $link['target_fragment'] ) : '';
		$href     = (string) $permalink;

		if ( '' === $fragment ) {
			return $href;
		}

		return preg_replace( '/#.*$/', '', $href ) . '#' . ltrim( $fragment, '#' );
	}

	/**
	 * Replaces one href value in block markup.
	 *
	 * @param string $block_markup Prepared block markup.
	 * @param string $from_href    Placeholder href.
	 * @param string $to_href      Resolved href.
	 * @return string
	 */
	private function replace_href( $block_markup, $from_href, $to_href ) {
		return preg_replace_callback(
			'/\bhref\s*=\s*(["\'])(.*?)\1/is',
			function ( $matches ) use ( $from_href, $to_href ) {
				$current = html_entity_decode( $matches[2], ENT_QUOTES, 'UTF-8' );

				if ( $current !== $from_href ) {
					return $matches[0];
				}

				return 'href=' . $matches[1] . htmlspecialchars( $to_href, ENT_QUOTES, 'UTF-8' ) . $matches[1];
			},
			(string) $block_markup
		);
	}

	/**
	 * Records a bounded deferred diagnostic when all pending links are blocked.
	 *
	 * @param ImportSession                  $session        Session.
	 * @param ImportPreparedDocument         $document       Prepared document.
	 * @param array<int,array<string,mixed>> $deferred_links Deferred links.
	 * @return void
	 */
	private function record_deferred_event( ImportSession $session, ImportPreparedDocument $document, array $deferred_links ) {
		if ( empty( $deferred_links ) ) {
			return;
		}

		$this->store->record_event(
			$session->get_id(),
			new ImportProgressEvent(
				ImportProgressEvent::LEVEL_WARNING,
				'epub.internal_links_deferred',
				'EPUB internal links are waiting for target pages or permalinks.',
				array(
					'source_item_key' => $document->get_source_item_key(),
					'deferred'        => count( $deferred_links ),
				)
			)
		);
	}
}
