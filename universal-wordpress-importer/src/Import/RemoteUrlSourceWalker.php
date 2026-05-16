<?php
/**
 * Remote URL and WordPress REST source walker.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

use RuntimeException;

/**
 * Imports remote HTTP(S) sources through WP REST when available.
 */
final class RemoteUrlSourceWalker {
	const REST_PAGE_LIMIT      = 25;
	const REST_COMMENT_LIMIT   = 25;
	const REST_EMBED_RELATIONS = 'author,wp:term,wp:featuredmedia';
	const RSS_ITEM_LIMIT       = 50;

	/**
	 * Built-in REST collection bases used when post type discovery is unavailable.
	 *
	 * @var array<int,string>
	 */
	private static $default_rest_endpoints = array( 'pages', 'posts' );

	/**
	 * Durable store.
	 *
	 * @var WordPressImportSessionStore
	 */
	private $store;

	/**
	 * Remote content fetcher.
	 *
	 * @var ImportRemoteContentFetcherInterface
	 */
	private $fetcher;

	/**
	 * Hidden failure simulation controls for adversarial tests.
	 *
	 * @var ImportRunnerControls
	 */
	private $controls;

	/**
	 * Constructor.
	 *
	 * @param WordPressImportSessionStore              $store   Durable store.
	 * @param ImportRemoteContentFetcherInterface|null $fetcher Optional fetcher.
	 * @param ImportRunnerControls|null                $controls Optional hidden test controls.
	 */
	public function __construct( WordPressImportSessionStore $store, ImportRemoteContentFetcherInterface $fetcher = null, ImportRunnerControls $controls = null ) {
		$this->store    = $store;
		$this->fetcher  = null === $fetcher ? new WordPressRemoteContentFetcher() : $fetcher;
		$this->controls = null === $controls ? ImportRunnerControls::none() : $controls;
	}

	/**
	 * Advances remote URL discovery for a session.
	 *
	 * @param ImportSession $session Session.
	 * @return array{discovered:int,queued:int,failed:int,complete:bool,message:string}
	 */
	public function advance( ImportSession $session ) {
		$source = trim( $session->get_source() );

		if ( ! $this->is_remote_http_source( $source ) || $this->is_github_source( $source ) ) {
			return array(
				'discovered' => 0,
				'queued'     => 0,
				'failed'     => 0,
				'complete'   => false,
				'message'    => 'Source is not a remote URL.',
			);
		}

		$root_key = 'remote:' . hash( 'sha256', $source );
		$root     = $this->store->find_source_item( $session->get_id(), $root_key );

		if ( null === $root ) {
			$root = ImportSourceItem::queued(
				$session->get_id(),
				$root_key,
				null,
				$source,
				$source,
				ImportSourceItem::TYPE_DIRECTORY,
				array(
					'remote_source_url' => $source,
					'remote_status'     => 'pending',
				)
			)->with_status( ImportSourceItem::STATUS_PROCESSING );
			$this->store->save_source_item( $root );
		}

		$metadata = $root->get_metadata();
		$deferred = $this->deferred_rate_limit_summary( $metadata );

		if ( null !== $deferred ) {
			return $deferred;
		}

		$metadata = $this->clear_expired_rate_limit( $metadata );
		$root     = $root->with_metadata( $metadata );

		if ( ! empty( $metadata['remote_complete'] ) || ImportSourceItem::STATUS_FAILED === $root->get_status() ) {
			return array(
				'discovered' => isset( $metadata['remote_documents_prepared'] ) ? (int) $metadata['remote_documents_prepared'] : 0,
				'queued'     => 0,
				'failed'     => ImportSourceItem::STATUS_FAILED === $root->get_status() ? 1 : 0,
				'complete'   => true,
				'message'    => ImportSourceItem::STATUS_FAILED === $root->get_status() ? 'Remote URL traversal previously failed.' : 'Remote URL traversal is already complete.',
			);
		}

		try {
			return $this->advance_wordpress_rest_or_single_url( $session, $root );
		} catch ( ImportRemoteRateLimitException $exception ) {
			$fresh_root    = $this->store->find_source_item( $session->get_id(), $root->get_key() );
			$rate_root     = null === $fresh_root ? $root : $fresh_root;
			$rate_metadata = $rate_root->get_metadata();
			$this->store->save_source_item(
				$rate_root->with_metadata(
					$this->rate_limited_metadata( $rate_metadata, $exception )
				)
			);
			$this->record_event(
				$session,
				'remote.rate_limited',
				'Remote source asked the importer to back off; traversal will retry after the stored delay.',
				$rate_root,
				array(
					'url'                 => $exception->get_url(),
					'status_code'         => $exception->get_status_code(),
					'retry_after_header'  => $exception->get_retry_after_header(),
					'retry_after_seconds' => $exception->get_retry_after_seconds(),
				)
			);

			return array(
				'discovered' => 0,
				'queued'     => 0,
				'failed'     => 0,
				'complete'   => false,
				'message'    => 'Remote source is rate limited; traversal will retry after the stored backoff delay.',
			);
		} catch ( RuntimeException $exception ) {
			$this->store->save_source_item(
				$root->with_status( ImportSourceItem::STATUS_FAILED )->with_metadata(
					array_merge(
						$metadata,
						array(
							'remote_status' => 'failed',
							'error'         => $exception->getMessage(),
						)
					)
				)
			);
			$this->record_event( $session, 'remote.failed', $exception->getMessage(), $root, array() );

			return array(
				'discovered' => 0,
				'queued'     => 0,
				'failed'     => 1,
				'complete'   => true,
				'message'    => $exception->getMessage(),
			);
		}
	}

	/**
	 * Advances WP REST traversal or falls back to a single remote document.
	 *
	 * @param ImportSession    $session Session.
	 * @param ImportSourceItem $root    Root remote source item.
	 * @return array{discovered:int,queued:int,failed:int,complete:bool,message:string}
	 * @throws RuntimeException When single remote URL fallback fails.
	 */
	private function advance_wordpress_rest_or_single_url( ImportSession $session, ImportSourceItem $root ) {
		$metadata = $root->get_metadata();

		if ( ! isset( $metadata['remote_mode'] ) ) {
			try {
				$detection = $this->detect_rest_index( $root->get_source_uri() );

				$metadata = array_merge(
					$metadata,
					array(
						'remote_mode'             => 'wp-rest',
						'remote_rest_url'         => $detection['rest_url'],
						'remote_rest_discovery'   => $detection['discovery'],
						'remote_rest_detected_by' => $detection['detected_by'],
						'endpoint_index'          => 0,
						'endpoint_page'           => 1,
					)
				);
				$this->store->save_source_item( $root->with_metadata( $metadata ) );
				$this->record_event(
					$session,
					'remote.wp_rest_detected',
					'Remote WordPress REST API was detected.',
					$root,
					array(
						'rest_url'    => $metadata['remote_rest_url'],
						'detected_by' => $metadata['remote_rest_detected_by'],
					)
				);
			} catch ( RuntimeException $exception ) {
				if ( $exception instanceof ImportRemoteRateLimitException ) {
					throw $exception;
				}

				return $this->prepare_single_remote_url( $session, $root, $exception->getMessage() );
			}
		}

		return $this->advance_wp_rest( $session, $root->with_metadata( $metadata ) );
	}

	/**
	 * Advances one page of WP REST posts or pages.
	 *
	 * @param ImportSession    $session Session.
	 * @param ImportSourceItem $root    Root remote source item.
	 * @return array{discovered:int,queued:int,failed:int,complete:bool,message:string}
	 * @throws ImportRemoteRateLimitException When the remote server asks the importer to retry later.
	 */
	private function advance_wp_rest( ImportSession $session, ImportSourceItem $root ) {
		$metadata  = $root->get_metadata();
		$endpoints = isset( $metadata['remote_rest_endpoints'] ) && is_array( $metadata['remote_rest_endpoints'] ) ? array_values( $metadata['remote_rest_endpoints'] ) : array();

		if ( empty( $endpoints ) ) {
			$endpoints                         = $this->discover_rest_endpoints( $session, $root );
			$metadata                          = $root->get_metadata();
			$metadata['remote_rest_endpoints'] = $endpoints;
			$this->store->save_source_item( $root->with_metadata( $metadata ) );
		}

		$endpoint = isset( $metadata['endpoint_index'] ) ? (int) $metadata['endpoint_index'] : 0;
		$page     = isset( $metadata['endpoint_page'] ) ? (int) $metadata['endpoint_page'] : 1;
		$prepared = 0;

		while ( isset( $endpoints[ $endpoint ] ) ) {
			$url = $this->rest_collection_url( $metadata['remote_rest_url'], $endpoints[ $endpoint ], $page );
			try {
				$items = $this->fetcher->fetch_json( $url );
			} catch ( ImportRemoteRateLimitException $exception ) {
				throw $exception;
			} catch ( RuntimeException $exception ) {
				$metadata['remote_rest_page_warnings'] = $this->append_remote_rest_page_warning(
					isset( $metadata['remote_rest_page_warnings'] ) && is_array( $metadata['remote_rest_page_warnings'] ) ? $metadata['remote_rest_page_warnings'] : array(),
					$endpoints[ $endpoint ],
					$page,
					$exception->getMessage()
				);
				$this->store->save_source_item( $root->with_metadata( $metadata ) );
				$this->record_event(
					$session,
					'remote.wp_rest_page_unavailable',
					'Remote WordPress REST collection page was unavailable; traversal will continue with the next collection.',
					$root,
					array(
						'endpoint' => $endpoints[ $endpoint ],
						'page'     => $page,
						'error'    => $exception->getMessage(),
					)
				);
				++$endpoint;
				$page = 1;
				continue;
			}

			if ( ! $this->is_list_array( $items ) ) {
				$error                                 = 'Remote WordPress REST collection endpoint returned a non-list payload.';
				$metadata['remote_rest_page_warnings'] = $this->append_remote_rest_page_warning(
					isset( $metadata['remote_rest_page_warnings'] ) && is_array( $metadata['remote_rest_page_warnings'] ) ? $metadata['remote_rest_page_warnings'] : array(),
					$endpoints[ $endpoint ],
					$page,
					$error
				);
				$this->store->save_source_item( $root->with_metadata( $metadata ) );
				$this->record_event(
					$session,
					'remote.wp_rest_page_unavailable',
					'Remote WordPress REST collection returned an unexpected payload; traversal will continue with the next collection.',
					$root,
					array(
						'endpoint'      => $endpoints[ $endpoint ],
						'page'          => $page,
						'error'         => $error,
						'payload_shape' => $this->describe_payload_shape( $items ),
					)
				);
				++$endpoint;
				$page = 1;
				continue;
			}

			if ( empty( $items ) ) {
				++$endpoint;
				$page = 1;
				continue;
			}

			foreach ( $items as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				$item_key = $this->prepare_rest_document( $session, $root, $endpoints[ $endpoint ], $item );

				if ( false === $item_key ) {
					continue;
				}

				$metadata['remote_comments_queue'] = $this->append_remote_comment_queue_entry(
					isset( $metadata['remote_comments_queue'] ) && is_array( $metadata['remote_comments_queue'] ) ? $metadata['remote_comments_queue'] : array(),
					$endpoints[ $endpoint ],
					$item,
					$item_key
				);
				++$prepared;
			}

			$metadata['endpoint_index']            = $endpoint;
			$metadata['endpoint_page']             = $page + 1;
			$metadata['remote_documents_prepared'] = ( isset( $metadata['remote_documents_prepared'] ) ? (int) $metadata['remote_documents_prepared'] : 0 ) + $prepared;
			$metadata['remote_status']             = 'partial';
			$this->store->save_source_item( $root->with_metadata( $metadata ) );
			$this->record_event(
				$session,
				'remote.wp_rest_page_prepared',
				'Remote WordPress REST collection page was staged as prepared documents.',
				$root,
				array(
					'endpoint' => $endpoints[ $endpoint ],
					'page'     => $page,
					'prepared' => $prepared,
				)
			);

			if ( $this->controls->should_simulate_fatal_after_rest_page_cursor() ) {
				$this->record_event(
					$session,
					'runner.simulated_fatal_after_rest_page_cursor',
					'Runner is terminating PHP after a durable REST page cursor write for recovery testing.',
					$root,
					array(
						'endpoint'  => $endpoints[ $endpoint ],
						'page'      => $page,
						'next_page' => $page + 1,
						'prepared'  => $prepared,
					)
				);

				exit( 125 );
			}

			return array(
				'discovered' => $prepared,
				'queued'     => $prepared,
				'failed'     => 0,
				'complete'   => false,
				'message'    => 'Remote WordPress REST collection page was imported and can resume from the stored cursor.',
			);
		}

		$comments = $this->advance_wp_rest_comments( $session, $root->with_metadata( $metadata ) );

		if ( empty( $comments['complete'] ) ) {
			return array(
				'discovered' => 0,
				'queued'     => $comments['staged'],
				'failed'     => 0,
				'complete'   => false,
				'message'    => $comments['message'],
			);
		}

		$fresh_root = $this->store->find_source_item( $session->get_id(), $root->get_key() );
		if ( null !== $fresh_root ) {
			$root     = $fresh_root;
			$metadata = $root->get_metadata();
		}

		unset( $metadata['endpoint_index'], $metadata['endpoint_page'], $metadata['comments_queue_index'], $metadata['comments_page'] );
		$metadata['remote_complete'] = true;
		$metadata['remote_status']   = 'complete';
		$this->store->save_source_item( $root->with_status( ImportSourceItem::STATUS_SKIPPED )->with_replaced_metadata( $metadata ) );
		$this->record_event( $session, 'remote.wp_rest_complete', 'Remote WordPress REST traversal is complete.', $root, array( 'prepared' => isset( $metadata['remote_documents_prepared'] ) ? (int) $metadata['remote_documents_prepared'] : 0 ) );

		return array(
			'discovered' => 0,
			'queued'     => 0,
			'failed'     => 0,
			'complete'   => true,
			'message'    => 'Remote WordPress REST traversal is complete.',
		);
	}

	/**
	 * Discovers importable REST post collection bases from wp/v2/types.
	 *
	 * @param ImportSession    $session Session.
	 * @param ImportSourceItem $root    Root remote source item.
	 * @return array<int,string>
	 * @throws ImportRemoteRateLimitException When the remote server asks the importer to retry later.
	 * @throws RuntimeException When root metadata is missing the REST URL.
	 */
	private function discover_rest_endpoints( ImportSession $session, ImportSourceItem $root ) {
		$rest_url  = $root->get_metadata()['remote_rest_url'];
		$endpoints = array();

		try {
			$types = $this->fetcher->fetch_json( $this->rest_types_url( $rest_url ) );

			if ( ! is_array( $types ) ) {
				throw new RuntimeException( 'Remote WordPress REST post type index was not an object.' );
			}

			foreach ( $types as $slug => $type ) {
				if ( ! is_array( $type ) || ! $this->is_importable_rest_type( (string) $slug, $type ) ) {
					continue;
				}

				$rest_base = isset( $type['rest_base'] ) && is_string( $type['rest_base'] ) && '' !== trim( $type['rest_base'] ) ? trim( $type['rest_base'], '/' ) : (string) $slug;
				$endpoints = $this->append_unique_endpoint( $endpoints, $rest_base );
			}

			$endpoints = $this->prioritize_default_rest_endpoints( $endpoints );
			$this->record_event(
				$session,
				'remote.wp_rest_types_detected',
				'Remote WordPress REST post type collections were discovered.',
				$root,
				array( 'endpoints' => $endpoints )
			);
		} catch ( ImportRemoteRateLimitException $exception ) {
			throw $exception;
		} catch ( RuntimeException $exception ) {
			$endpoints = self::$default_rest_endpoints;
			$this->record_event(
				$session,
				'remote.wp_rest_types_fallback',
				'Remote WordPress REST post type discovery failed; falling back to pages and posts.',
				$root,
				array( 'error' => $exception->getMessage() )
			);
		}

		return empty( $endpoints ) ? self::$default_rest_endpoints : $endpoints;
	}

	/**
	 * Prepares a single REST post/page as a document.
	 *
	 * @param ImportSession       $session  Session.
	 * @param ImportSourceItem    $root     Root source item.
	 * @param string              $endpoint Endpoint name.
	 * @param array<string,mixed> $item     REST item.
	 * @return string|false Prepared source item key, or false when no document was prepared.
	 */
	private function prepare_rest_document( ImportSession $session, ImportSourceItem $root, $endpoint, array $item ) {
		$id = isset( $item['id'] ) ? (string) (int) $item['id'] : '';

		if ( '' === $id ) {
			return false;
		}

		$item_key = 'remote-rest:' . hash( 'sha256', $root->get_source_uri() . "\n" . $endpoint . "\n" . $id );

		if ( null !== $this->store->find_prepared_document( $session->get_id(), $item_key ) ) {
			return false;
		}

		$title          = $this->rendered_value( isset( $item['title'] ) ? $item['title'] : array(), 'Remote ' . $endpoint . ' ' . $id );
		$content        = $this->rendered_value( isset( $item['content'] ) ? $item['content'] : array(), '' );
		$html_summary   = array();
		$content_markup = ( new ImportHtmlBlockConverter() )->convert( $content, $html_summary );
		$featured_media = $this->featured_media_from_rest_item( $session, $root, $item );

		if ( '' === $content_markup && null === $featured_media ) {
			return false;
		}

		$markup   = $this->featured_media_block_markup( $featured_media );
		$markup  .= $content_markup;
		$blocks   = ( null === $featured_media ? 0 : 1 ) + $this->count_blocks( $content_markup );
		$source   = isset( $item['link'] ) && is_string( $item['link'] ) ? $item['link'] : $root->get_source_uri();
		$domains  = $this->extract_absolute_url_domains( $markup );
		$metadata = array(
			'remote_source_url'     => $source,
			'remote_rest_endpoint'  => $endpoint,
			'remote_rest_id'        => (int) $id,
			'absolute_url_domains'  => array_keys( $domains ),
			'absolute_url_examples' => $domains,
		);
		$metadata = array_merge( $metadata, $html_summary, $this->rest_relationship_metadata( $item ) );

		if ( null !== $featured_media ) {
			$metadata['remote_featured_media_id']  = $featured_media['id'];
			$metadata['remote_featured_media_url'] = $featured_media['source_url'];
		}

		$document = new ImportPreparedDocument(
			$session->get_id(),
			$item_key,
			'wp-rest',
			$title,
			$markup,
			$blocks,
			hash( 'sha256', 'wp-rest' . "\n" . $source . "\n" . $markup ),
			$metadata
		);

		$this->store->save_source_item(
			ImportSourceItem::queued(
				$session->get_id(),
				$item_key,
				$root->get_key(),
				$source,
				$endpoint . '/' . $id,
				ImportSourceItem::TYPE_FILE,
				array(
					'document_format'           => 'wp-rest',
					'processor_status'          => 'imported',
					'remote_rest_endpoint'      => $endpoint,
					'remote_rest_id'            => (int) $id,
					'remote_author_id'          => isset( $metadata['remote_author_id'] ) ? (int) $metadata['remote_author_id'] : null,
					'remote_terms'              => isset( $metadata['remote_terms'] ) ? $metadata['remote_terms'] : array(),
					'title'                     => $title,
					'block_count'               => $blocks,
					'content_hash'              => $document->get_content_hash(),
					'html_block_conversion'     => $metadata['html_block_conversion'],
					'html_inferred_block_count' => $metadata['html_inferred_block_count'],
					'html_classic_block_count'  => $metadata['html_classic_block_count'],
				)
			)->with_status( ImportSourceItem::STATUS_IMPORTED )
		);
		$this->store->save_prepared_document( $document );
		$this->store->remember_idempotency_record(
			$session->get_id(),
			new ImportIdempotencyRecord( 'document-blocks:' . $item_key, 'prepared_document', $item_key, $document->get_content_hash() )
		);

		return $item_key;
	}

	/**
	 * Advances durable REST comment staging for prepared remote documents.
	 *
	 * @param ImportSession    $session Session.
	 * @param ImportSourceItem $root    Root source item.
	 * @return array{staged:int,complete:bool,message:string}
	 * @throws ImportRemoteRateLimitException When the remote server asks the importer to retry later.
	 */
	private function advance_wp_rest_comments( ImportSession $session, ImportSourceItem $root ) {
		$metadata = $root->get_metadata();
		$queue    = isset( $metadata['remote_comments_queue'] ) && is_array( $metadata['remote_comments_queue'] ) ? array_values( $metadata['remote_comments_queue'] ) : array();

		if ( empty( $queue ) ) {
			$metadata['remote_comments_complete'] = true;
			$this->store->save_source_item( $root->with_metadata( $metadata ) );

			return array(
				'staged'   => 0,
				'complete' => true,
				'message'  => 'Remote WordPress REST traversal has no comment-enabled documents to inspect.',
			);
		}

		$index = isset( $metadata['comments_queue_index'] ) ? max( 0, (int) $metadata['comments_queue_index'] ) : 0;
		$page  = isset( $metadata['comments_page'] ) ? max( 1, (int) $metadata['comments_page'] ) : 1;

		while ( isset( $queue[ $index ] ) ) {
			$entry = is_array( $queue[ $index ] ) ? $queue[ $index ] : array();

			if ( empty( $entry['source_item_key'] ) || empty( $entry['remote_rest_id'] ) || empty( $metadata['remote_rest_url'] ) ) {
				++$index;
				$page = 1;
				continue;
			}

			$document = $this->store->find_prepared_document( $session->get_id(), (string) $entry['source_item_key'] );

			if ( null === $document ) {
				++$index;
				$page = 1;
				continue;
			}

			$url = $this->rest_comments_url( $metadata['remote_rest_url'], (int) $entry['remote_rest_id'], $page );

			try {
				$comments = $this->fetcher->fetch_json( $url );
			} catch ( ImportRemoteRateLimitException $exception ) {
				throw $exception;
			} catch ( RuntimeException $exception ) {
				$this->mark_rest_comments_complete_for_document( $session, $root, $document, $entry, $page, 1 === $page ? $exception->getMessage() : null );
				++$index;
				$page = 1;
				continue;
			}

			if ( ! $this->is_list_array( $comments ) ) {
				$this->mark_rest_comments_complete_for_document( $session, $root, $document, $entry, $page, 'Remote WordPress REST comments endpoint returned a non-list payload.' );
				++$index;
				$page = 1;
				continue;
			}

			if ( empty( $comments ) || ! is_array( $comments ) ) {
				$this->mark_rest_comments_complete_for_document( $session, $root, $document, $entry, $page, null );
				++$index;
				$page = 1;
				continue;
			}

			$normalized = array();

			foreach ( $comments as $comment ) {
				if ( ! is_array( $comment ) ) {
					continue;
				}

				$normalized_comment = $this->normalize_comment_entity( $comment, $entry );

				if ( null !== $normalized_comment ) {
					$normalized[] = $normalized_comment;
				}
			}

			$document_metadata = $document->get_metadata();
			$merged_comments   = $this->merge_remote_comments(
				isset( $document_metadata['remote_comments'] ) && is_array( $document_metadata['remote_comments'] ) ? $document_metadata['remote_comments'] : array(),
				$normalized
			);

			$document_metadata['remote_comments']           = $merged_comments;
			$document_metadata['remote_comment_count']      = count( $merged_comments );
			$document_metadata['remote_comments_last_page'] = $page;
			$document_metadata['remote_comments_complete']  = false;
			$this->store->save_prepared_document( $document->with_metadata( $document_metadata ) );
			$this->update_comment_source_item_metadata( $session, $document->get_source_item_key(), count( $merged_comments ), false, null );

			$this->store->remember_idempotency_record(
				$session->get_id(),
				new ImportIdempotencyRecord(
					'rest-comments:' . $document->get_source_item_key() . ':page:' . $page,
					'remote_comments',
					$document->get_source_item_key() . ':' . $page,
					hash( 'sha256', $this->encode_json( $normalized ) )
				)
			);

			$metadata['comments_queue_index'] = $index;
			$metadata['comments_page']        = $page + 1;
			$metadata['remote_comments_seen'] = ( isset( $metadata['remote_comments_seen'] ) ? (int) $metadata['remote_comments_seen'] : 0 ) + count( $normalized );
			$this->store->save_source_item( $root->with_metadata( $metadata ) );
			$this->record_event(
				$session,
				'remote.wp_rest_comments_staged',
				'Remote WordPress REST comments were staged for a prepared document.',
				$root,
				array(
					'source_item_key' => $document->get_source_item_key(),
					'remote_rest_id'  => (int) $entry['remote_rest_id'],
					'page'            => $page,
					'staged'          => count( $normalized ),
					'total'           => count( $merged_comments ),
				)
			);

			return array(
				'staged'   => count( $normalized ),
				'complete' => false,
				'message'  => 'Remote WordPress REST comments were staged and can resume from the stored comment cursor.',
			);
		}

		$metadata['remote_comments_complete'] = true;
		unset( $metadata['comments_queue_index'], $metadata['comments_page'] );
		$this->store->save_source_item( $root->with_metadata( $metadata ) );

		return array(
			'staged'   => 0,
			'complete' => true,
			'message'  => 'Remote WordPress REST comment staging is complete.',
		);
	}

	/**
	 * Prepares a single remote URL as an HTML classic block document.
	 *
	 * @param ImportSession    $session          Session.
	 * @param ImportSourceItem $root             Root remote item.
	 * @param string           $rest_failure_msg REST detection failure.
	 * @return array{discovered:int,queued:int,failed:int,complete:bool,message:string}
	 * @throws ImportRemoteRateLimitException When a discovered feed asks the importer to retry later.
	 * @throws RuntimeException When the remote URL is not importable.
	 */
	private function prepare_single_remote_url( ImportSession $session, ImportSourceItem $root, $rest_failure_msg ) {
		$response = $this->fetcher->fetch_text( $root->get_source_uri() );
		$feed     = $this->parse_feed_response( $response['body'], $root->get_source_uri() );

		if ( null !== $feed ) {
			return $this->prepare_feed_documents( $session, $root, $root->get_source_uri(), $feed, 'direct-feed', $rest_failure_msg );
		}

		$feed_url = $this->discover_feed_url_from_html_response( $root->get_source_uri(), $response );

		if ( '' !== $feed_url ) {
			try {
				$feed_response = $this->fetcher->fetch_text( $feed_url );
				$feed          = $this->parse_feed_response( $feed_response['body'], $feed_url );

				if ( null !== $feed ) {
					return $this->prepare_feed_documents( $session, $root, $feed_url, $feed, 'html-feed-link', $rest_failure_msg );
				}
			} catch ( ImportRemoteRateLimitException $exception ) {
				throw $exception;
			} catch ( RuntimeException $exception ) {
				$this->record_event(
					$session,
					'remote.feed_unavailable',
					'Remote RSS/Atom feed link could not be fetched; falling back to the source page.',
					$root,
					array(
						'feed_url' => $feed_url,
						'error'    => $exception->getMessage(),
					)
				);
			}
		}

		$content = ( new ImportHtmlBlockConverter() )->extract_body( $response['body'] );

		if ( '' === $content ) {
			throw new RuntimeException( 'Remote URL did not contain importable HTML or text content.' );
		}

		$html_summary = array();
		$markup       = ( new ImportHtmlBlockConverter() )->convert( $content, $html_summary );
		$block_count  = $this->count_blocks( $markup );
		$item_key     = 'remote-url:' . hash( 'sha256', $root->get_source_uri() );
		$title        = $this->title_from_html( $response['body'], $root->get_source_uri() );
		$domains      = $this->extract_absolute_url_domains( $markup );
		$document     = new ImportPreparedDocument(
			$session->get_id(),
			$item_key,
			'remote-html',
			$title,
			$markup,
			$block_count,
			hash( 'sha256', 'remote-html' . "\n" . $root->get_source_uri() . "\n" . $content ),
			array_merge(
				array(
					'remote_source_url'     => $root->get_source_uri(),
					'remote_rest_fallback'  => $rest_failure_msg,
					'absolute_url_domains'  => array_keys( $domains ),
					'absolute_url_examples' => $domains,
				),
				$html_summary
			)
		);

		$this->store->save_source_item(
			ImportSourceItem::queued(
				$session->get_id(),
				$item_key,
				$root->get_key(),
				$root->get_source_uri(),
				$root->get_source_uri(),
				ImportSourceItem::TYPE_FILE,
				array(
					'document_format'           => 'remote-html',
					'processor_status'          => 'imported',
					'title'                     => $title,
					'block_count'               => $block_count,
					'content_hash'              => $document->get_content_hash(),
					'html_block_conversion'     => $html_summary['html_block_conversion'],
					'html_inferred_block_count' => $html_summary['html_inferred_block_count'],
					'html_classic_block_count'  => $html_summary['html_classic_block_count'],
				)
			)->with_status( ImportSourceItem::STATUS_IMPORTED )
		);
		$this->store->save_prepared_document( $document );
		$this->store->remember_idempotency_record(
			$session->get_id(),
			new ImportIdempotencyRecord( 'document-blocks:' . $item_key, 'prepared_document', $item_key, $document->get_content_hash() )
		);
		$this->store->save_source_item(
			$root->with_status( ImportSourceItem::STATUS_SKIPPED )->with_metadata(
				array_merge(
					$root->get_metadata(),
					array(
						'remote_mode'               => 'single-url',
						'remote_complete'           => true,
						'remote_status'             => 'complete',
						'remote_documents_prepared' => 1,
						'remote_rest_fallback'      => $rest_failure_msg,
					)
				)
			)
		);
		$this->record_event( $session, 'remote.url_prepared', 'Remote URL was staged as a prepared HTML document.', $root, array( 'rest_fallback' => $rest_failure_msg ) );

		return array(
			'discovered' => 1,
			'queued'     => 1,
			'failed'     => 0,
			'complete'   => true,
			'message'    => 'Remote URL was staged as a prepared HTML document.',
		);
	}

	/**
	 * Prepares RSS or Atom items as remote documents.
	 *
	 * @param ImportSession       $session          Session.
	 * @param ImportSourceItem    $root             Root remote item.
	 * @param string              $feed_url         Feed URL.
	 * @param array<string,mixed> $feed             Parsed feed payload.
	 * @param string              $discovered_by    Feed discovery method.
	 * @param string              $rest_failure_msg REST detection failure.
	 * @return array{discovered:int,queued:int,failed:int,complete:bool,message:string}
	 * @throws RuntimeException When the feed has no importable content.
	 */
	private function prepare_feed_documents( ImportSession $session, ImportSourceItem $root, $feed_url, array $feed, $discovered_by, $rest_failure_msg ) {
		$prepared = 0;
		$items    = isset( $feed['items'] ) && is_array( $feed['items'] ) ? array_slice( $feed['items'], 0, self::RSS_ITEM_LIMIT ) : array();

		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			if ( $this->prepare_feed_item_document( $session, $root, $feed_url, $feed, $item, (int) $index ) ) {
				++$prepared;
			}
		}

		if ( 0 === $prepared ) {
			throw new RuntimeException( 'Remote RSS/Atom feed did not contain importable items.' );
		}

		$metadata = array_merge(
			$root->get_metadata(),
			array(
				'remote_mode'               => 'rss',
				'remote_complete'           => true,
				'remote_status'             => 'complete',
				'remote_documents_prepared' => $prepared,
				'remote_feed_url'           => $feed_url,
				'remote_feed_title'         => isset( $feed['title'] ) ? (string) $feed['title'] : '',
				'remote_feed_discovered_by' => (string) $discovered_by,
				'remote_rest_fallback'      => $rest_failure_msg,
			)
		);

		$this->store->save_source_item( $root->with_status( ImportSourceItem::STATUS_SKIPPED )->with_metadata( $metadata ) );
		$this->record_event(
			$session,
			'remote.feed_prepared',
			'Remote RSS/Atom feed items were staged as prepared documents.',
			$root,
			array(
				'feed_url'      => $feed_url,
				'discovered_by' => (string) $discovered_by,
				'prepared'      => $prepared,
			)
		);

		return array(
			'discovered' => $prepared,
			'queued'     => $prepared,
			'failed'     => 0,
			'complete'   => true,
			'message'    => 'Remote RSS/Atom feed items were staged as prepared documents.',
		);
	}

	/**
	 * Prepares one RSS or Atom item as a document.
	 *
	 * @param ImportSession       $session  Session.
	 * @param ImportSourceItem    $root     Root remote item.
	 * @param string              $feed_url Feed URL.
	 * @param array<string,mixed> $feed     Parsed feed payload.
	 * @param array<string,mixed> $item     Parsed feed item.
	 * @param int                 $index    Feed item index.
	 * @return bool Whether a document was prepared.
	 */
	private function prepare_feed_item_document( ImportSession $session, ImportSourceItem $root, $feed_url, array $feed, array $item, $index ) {
		$source = isset( $item['link'] ) && '' !== trim( (string) $item['link'] ) ? trim( (string) $item['link'] ) : $feed_url . '#item-' . ( $index + 1 );
		$id     = isset( $item['id'] ) && '' !== trim( (string) $item['id'] ) ? trim( (string) $item['id'] ) : $source;
		$key    = 'remote-feed:' . hash( 'sha256', $root->get_source_uri() . "\n" . $feed_url . "\n" . $id . "\n" . (int) $index );

		if ( null !== $this->store->find_prepared_document( $session->get_id(), $key ) ) {
			return false;
		}

		$content = isset( $item['content'] ) ? trim( (string) $item['content'] ) : '';
		if ( '' === $content ) {
			$content = isset( $item['summary'] ) ? trim( (string) $item['summary'] ) : '';
		}

		if ( '' === $content ) {
			return false;
		}

		$html_summary = array();
		$content      = $this->feed_content_html_fragment( $content );
		$markup       = ( new ImportHtmlBlockConverter() )->convert( $content, $html_summary );
		$block_count  = $this->count_blocks( $markup );

		if ( '' === $markup || 0 === $block_count ) {
			return false;
		}

		$title    = isset( $item['title'] ) && '' !== trim( (string) $item['title'] ) ? trim( (string) $item['title'] ) : 'Feed item ' . ( $index + 1 );
		$domains  = $this->extract_absolute_url_domains( $markup );
		$meta     = array_merge(
			array(
				'remote_source_url'     => $source,
				'remote_feed_url'       => $feed_url,
				'remote_feed_title'     => isset( $feed['title'] ) ? (string) $feed['title'] : '',
				'remote_feed_item_id'   => $id,
				'remote_feed_item_date' => isset( $item['date'] ) ? (string) $item['date'] : '',
				'absolute_url_domains'  => array_keys( $domains ),
				'absolute_url_examples' => $domains,
			),
			$html_summary
		);
		$document = new ImportPreparedDocument(
			$session->get_id(),
			$key,
			'rss',
			$this->strip_all_tags( html_entity_decode( $title, ENT_QUOTES, 'UTF-8' ) ),
			$markup,
			$block_count,
			hash( 'sha256', 'rss' . "\n" . $source . "\n" . $markup ),
			$meta
		);

		$this->store->save_source_item(
			ImportSourceItem::queued(
				$session->get_id(),
				$key,
				$root->get_key(),
				$source,
				$this->feed_item_relative_path( $source, $index ),
				ImportSourceItem::TYPE_FILE,
				array(
					'document_format'           => 'rss',
					'processor_status'          => 'imported',
					'title'                     => $document->get_title(),
					'block_count'               => $block_count,
					'content_hash'              => $document->get_content_hash(),
					'remote_feed_url'           => $feed_url,
					'remote_feed_item_id'       => $id,
					'html_block_conversion'     => $html_summary['html_block_conversion'],
					'html_inferred_block_count' => $html_summary['html_inferred_block_count'],
					'html_classic_block_count'  => $html_summary['html_classic_block_count'],
				)
			)->with_status( ImportSourceItem::STATUS_IMPORTED )
		);
		$this->store->save_prepared_document( $document );
		$this->store->remember_idempotency_record(
			$session->get_id(),
			new ImportIdempotencyRecord( 'document-blocks:' . $key, 'prepared_document', $key, $document->get_content_hash() )
		);

		return true;
	}

	/**
	 * Checks whether a source is an HTTP(S) URL.
	 *
	 * @param string $source Source.
	 * @return bool
	 */
	private function is_remote_http_source( $source ) {
		$parts = $this->parse_url_compat( $source );

		return is_array( $parts ) && ! empty( $parts['scheme'] ) && in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) && ! empty( $parts['host'] );
	}

	/**
	 * Checks whether a source belongs to GitHub's repository walker.
	 *
	 * @param string $source Source.
	 * @return bool
	 */
	private function is_github_source( $source ) {
		$parts = $this->parse_url_compat( $source );

		return is_array( $parts ) && ! empty( $parts['host'] ) && 'github.com' === strtolower( $parts['host'] );
	}

	/**
	 * Builds a likely WordPress REST index URL.
	 *
	 * @param string $source Source URL.
	 * @return string
	 */
	private function rest_index_url( $source ) {
		$parts  = $this->parse_url_compat( $source );
		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'https';
		$host   = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
		$port   = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path   = isset( $parts['path'] ) ? '/' . trim( $parts['path'], '/' ) : '';

		if ( preg_match( '#/wp-json/?#', $path, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $scheme . '://' . $host . $port . substr( $path, 0, $matches[0][1] + strlen( $matches[0][0] ) );
		}

		return $scheme . '://' . $host . $port . '/wp-json/';
	}

	/**
	 * Finds and validates the REST index URL for a remote source.
	 *
	 * @param string $source Source URL.
	 * @return array{rest_url:string,discovery:array<int,array{url:string,source:string}>,detected_by:string}
	 * @throws ImportRemoteRateLimitException When the remote server asks the importer to retry later.
	 * @throws RuntimeException When no candidate exposes wp/v2.
	 */
	private function detect_rest_index( $source ) {
		$candidates = array(
			array(
				'url'    => $this->rest_index_url( $source ),
				'source' => 'guessed-wp-json',
			),
		);
		$errors     = array();

		foreach ( $candidates as $candidate ) {
			try {
				$index = $this->fetcher->fetch_json( $candidate['url'] );

				if ( ! $this->index_supports_wp_v2( $index ) ) {
					throw new RuntimeException( 'Remote WordPress REST index does not advertise wp/v2 support.' );
				}

				return array(
					'rest_url'    => $candidate['url'],
					'discovery'   => $candidates,
					'detected_by' => $candidate['source'],
				);
			} catch ( ImportRemoteRateLimitException $exception ) {
				throw $exception;
			} catch ( RuntimeException $exception ) {
				$errors[] = $candidate['source'] . ': ' . $exception->getMessage();
			}
		}

		try {
			$response   = $this->fetcher->fetch_text( $source );
			$candidates = array_merge( $candidates, $this->discover_rest_index_candidates_from_response( $source, $response ) );
		} catch ( ImportRemoteRateLimitException $exception ) {
			throw $exception;
		} catch ( RuntimeException $exception ) {
			$errors[] = 'source-document: ' . $exception->getMessage();
		}

		foreach ( $candidates as $candidate ) {
			if ( 'guessed-wp-json' === $candidate['source'] ) {
				continue;
			}

			try {
				$index = $this->fetcher->fetch_json( $candidate['url'] );

				if ( ! $this->index_supports_wp_v2( $index ) ) {
					throw new RuntimeException( 'Remote WordPress REST index does not advertise wp/v2 support.' );
				}

				return array(
					'rest_url'    => $candidate['url'],
					'discovery'   => $candidates,
					'detected_by' => $candidate['source'],
				);
			} catch ( ImportRemoteRateLimitException $exception ) {
				throw $exception;
			} catch ( RuntimeException $exception ) {
				$errors[] = $candidate['source'] . ': ' . $exception->getMessage();
			}
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
		throw new RuntimeException( 'Remote WordPress REST API could not be discovered. ' . implode( ' ', $errors ) );
	}

	/**
	 * Extracts REST discovery candidates from a source document response.
	 *
	 * @param string                                                          $source   Source URL.
	 * @param array{body:string,headers:array<string,string>,status_code:int} $response Remote response.
	 * @return array<int,array{url:string,source:string}>
	 */
	private function discover_rest_index_candidates_from_response( $source, array $response ) {
		$candidates = array();
		$headers    = isset( $response['headers'] ) && is_array( $response['headers'] ) ? $response['headers'] : array();

		foreach ( $headers as $name => $value ) {
			if ( 'link' !== strtolower( (string) $name ) ) {
				continue;
			}

			foreach ( $this->extract_api_root_links_from_link_header( (string) $value ) as $url ) {
				$candidates = $this->append_unique_rest_candidate( $candidates, $url, 'link-header', $source );
			}
		}

		foreach ( $this->extract_api_root_links_from_html( isset( $response['body'] ) ? (string) $response['body'] : '' ) as $url ) {
			$candidates = $this->append_unique_rest_candidate( $candidates, $url, 'html-link', $source );
		}

		return $candidates;
	}

	/**
	 * Checks for wp/v2 support in a REST index.
	 *
	 * @param array<string,mixed>|array<int,mixed> $index REST index.
	 * @return bool
	 */
	private function index_supports_wp_v2( array $index ) {
		if ( empty( $index['namespaces'] ) || ! is_array( $index['namespaces'] ) ) {
			return false;
		}

		return in_array( 'wp/v2', $index['namespaces'], true );
	}

	/**
	 * Builds a collection URL using documented pagination parameters.
	 *
	 * @param string $rest_url REST index URL.
	 * @param string $endpoint Endpoint name.
	 * @param int    $page     Page number.
	 * @return string
	 */
	private function rest_collection_url( $rest_url, $endpoint, $page ) {
		return $this->rest_route_url(
			$rest_url,
			'wp/v2/' . trim( (string) $endpoint, '/' ),
			array(
				'context'  => 'view',
				'per_page' => self::REST_PAGE_LIMIT,
				'page'     => max( 1, (int) $page ),
				'_embed'   => self::REST_EMBED_RELATIONS,
			)
		);
	}

	/**
	 * Builds the REST post type discovery URL.
	 *
	 * @param string $rest_url REST index URL.
	 * @return string
	 */
	private function rest_types_url( $rest_url ) {
		return $this->rest_route_url(
			$rest_url,
			'wp/v2/types',
			array(
				'context' => 'view',
			)
		);
	}

	/**
	 * Builds the REST media entity URL.
	 *
	 * @param string $rest_url REST index URL.
	 * @param int    $media_id Media id.
	 * @return string
	 */
	private function rest_media_url( $rest_url, $media_id ) {
		return $this->rest_route_url(
			$rest_url,
			'wp/v2/media/' . (int) $media_id,
			array(
				'context' => 'view',
			)
		);
	}

	/**
	 * Builds the REST comments collection URL for one remote post.
	 *
	 * @param string $rest_url REST index URL.
	 * @param int    $post_id  Remote post id.
	 * @param int    $page     Page number.
	 * @return string
	 */
	private function rest_comments_url( $rest_url, $post_id, $page ) {
		return $this->rest_route_url(
			$rest_url,
			'wp/v2/comments',
			array(
				'context'  => 'view',
				'post'     => (int) $post_id,
				'per_page' => self::REST_COMMENT_LIMIT,
				'page'     => max( 1, (int) $page ),
				'order'    => 'asc',
				'orderby'  => 'date_gmt',
			)
		);
	}

	/**
	 * Builds a REST route URL for pretty and plain-permalink REST roots.
	 *
	 * @param string              $rest_url REST index URL.
	 * @param string              $route    REST route without leading slash.
	 * @param array<string,mixed> $query    Query arguments.
	 * @return string
	 */
	private function rest_route_url( $rest_url, $route, array $query ) {
		$parts = $this->parse_url_compat( $rest_url );

		if ( ! is_array( $parts ) ) {
			return rtrim( $rest_url, '/' ) . '/' . trim( $route, '/' ) . '?' . $this->build_query_string( $query );
		}

		$existing_query = array();
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $existing_query );
		}

		if ( array_key_exists( 'rest_route', $existing_query ) ) {
			$existing_query['rest_route'] = '/' . trim( $route, '/' );
			$query                        = array_merge( $existing_query, $query );

			return $this->unparse_url_compat( $parts, '' ) . '?' . $this->build_query_string( $query );
		}

		return rtrim( $rest_url, '/' ) . '/' . trim( $route, '/' ) . '?' . $this->build_query_string( $query );
	}

	/**
	 * Extracts WordPress REST API root links from an HTTP Link header.
	 *
	 * @param string $header Link header value.
	 * @return array<int,string>
	 */
	private function extract_api_root_links_from_link_header( $header ) {
		$links = array();

		if ( ! preg_match_all( '#<([^>]+)>\s*;\s*rel=["\']?https://api\.w\.org/?["\']?#i', $header, $matches ) ) {
			return $links;
		}

		foreach ( $matches[1] as $url ) {
			$links[] = html_entity_decode( trim( (string) $url ), ENT_QUOTES, 'UTF-8' );
		}

		return $links;
	}

	/**
	 * Extracts WordPress REST API root links from HTML link elements.
	 *
	 * @param string $html HTML body.
	 * @return array<int,string>
	 */
	private function extract_api_root_links_from_html( $html ) {
		$links = array();

		if ( ! preg_match_all( '#<link\b[^>]*>#i', $html, $matches ) ) {
			return $links;
		}

		foreach ( $matches[0] as $tag ) {
			$rel  = $this->attribute_from_tag( $tag, 'rel' );
			$href = $this->attribute_from_tag( $tag, 'href' );

			if ( null === $href || null === $rel || 'https://api.w.org/' !== rtrim( strtolower( $rel ), '/' ) . '/' ) {
				continue;
			}

			$links[] = html_entity_decode( trim( $href ), ENT_QUOTES, 'UTF-8' );
		}

		return $links;
	}

	/**
	 * Finds an RSS or Atom feed URL advertised by an HTML page.
	 *
	 * @param string                                                          $source   Source URL.
	 * @param array{body:string,headers:array<string,string>,status_code:int} $response Remote response.
	 * @return string
	 */
	private function discover_feed_url_from_html_response( $source, array $response ) {
		$html = isset( $response['body'] ) ? (string) $response['body'] : '';

		if ( ! preg_match_all( '#<link\b[^>]*>#i', $html, $matches ) ) {
			return '';
		}

		foreach ( $matches[0] as $tag ) {
			$rel  = strtolower( (string) $this->attribute_from_tag( $tag, 'rel' ) );
			$type = strtolower( (string) $this->attribute_from_tag( $tag, 'type' ) );
			$href = $this->attribute_from_tag( $tag, 'href' );

			if ( null === $href || false === strpos( $rel, 'alternate' ) ) {
				continue;
			}

			if ( false === strpos( $type, 'rss+xml' ) && false === strpos( $type, 'atom+xml' ) && false === strpos( $type, 'rdf+xml' ) ) {
				continue;
			}

			$url = $this->absolute_url( html_entity_decode( trim( $href ), ENT_QUOTES, 'UTF-8' ), $source );

			if ( '' !== $url && $this->is_remote_http_source( $url ) && $this->same_url_host( $url, $source ) ) {
				return $url;
			}
		}

		return '';
	}

	/**
	 * Parses RSS, RDF, or Atom feed XML.
	 *
	 * @param string $body Feed response body.
	 * @param string $url  Feed URL for diagnostics.
	 * @return array{title:string,items:array<int,array<string,string>>}|null
	 */
	private function parse_feed_response( $body, $url ) {
		$body = trim( (string) $body );

		if ( '' === $body || '<' !== substr( $body, 0, 1 ) ) {
			return null;
		}

		$previous = libxml_use_internal_errors( true );
		$xml      = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( false === $xml ) {
			return null;
		}

		$name = strtolower( $xml->getName() );

		if ( 'rss' === $name ) {
			return $this->parse_rss_feed( $xml );
		}

		if ( 'feed' === $name ) {
			return $this->parse_atom_feed( $xml );
		}

		if ( 'rdf' === $name || false !== stripos( $body, '<rdf:RDF' ) ) {
			return $this->parse_rdf_feed( $xml, $url );
		}

		return null;
	}

	/**
	 * Parses an RSS 2.0 feed.
	 *
	 * @param \SimpleXMLElement $xml Feed XML.
	 * @return array{title:string,items:array<int,array<string,string>>}
	 */
	private function parse_rss_feed( \SimpleXMLElement $xml ) {
		$channel = isset( $xml->channel ) ? $xml->channel : $xml;
		$items   = array();

		foreach ( $channel->item as $item ) {
			$content_children = $item->children( 'http://purl.org/rss/1.0/modules/content/' );
			$encoded          = isset( $content_children->encoded ) ? $this->xml_node_text( $content_children->encoded ) : '';
			$items[]          = array(
				'id'      => $this->xml_node_text( isset( $item->guid ) ? $item->guid : $item->link ),
				'title'   => $this->xml_node_text( $item->title ),
				'link'    => $this->xml_node_text( $item->link ),
				'content' => '' === $encoded ? $this->xml_node_text( $item->description ) : $encoded,
				'summary' => $this->xml_node_text( $item->description ),
				'date'    => $this->xml_node_text( $item->pubDate ),
			);
		}

		return array(
			'title' => $this->xml_node_text( $channel->title ),
			'items' => $items,
		);
	}

	/**
	 * Parses an Atom feed.
	 *
	 * @param \SimpleXMLElement $xml Feed XML.
	 * @return array{title:string,items:array<int,array<string,string>>}
	 */
	private function parse_atom_feed( \SimpleXMLElement $xml ) {
		$items = array();

		foreach ( $xml->entry as $entry ) {
			$content = $this->xml_node_text( isset( $entry->content ) ? $entry->content : '' );
			$items[] = array(
				'id'      => $this->xml_node_text( isset( $entry->id ) ? $entry->id : $entry->link ),
				'title'   => $this->xml_node_text( $entry->title ),
				'link'    => $this->atom_entry_link( $entry ),
				'content' => '' === $content ? $this->xml_node_text( $entry->summary ) : $content,
				'summary' => $this->xml_node_text( $entry->summary ),
				'date'    => $this->xml_node_text( isset( $entry->updated ) ? $entry->updated : $entry->published ),
			);
		}

		return array(
			'title' => $this->xml_node_text( $xml->title ),
			'items' => $items,
		);
	}

	/**
	 * Parses an RDF/RSS 1.0 feed.
	 *
	 * @param \SimpleXMLElement $xml Feed XML.
	 * @param string            $url Feed URL.
	 * @return array{title:string,items:array<int,array<string,string>>}
	 */
	private function parse_rdf_feed( \SimpleXMLElement $xml, $url ) {
		$items = array();
		$nodes = $xml->xpath( '//*[local-name()="item"]' );

		foreach ( false === $nodes ? array() : $nodes as $item ) {
			$items[] = array(
				'id'      => $this->xml_node_text( isset( $item->link ) ? $item->link : $item->title ),
				'title'   => $this->xml_node_text( $item->title ),
				'link'    => $this->xml_node_text( $item->link ),
				'content' => $this->xml_node_text( isset( $item->description ) ? $item->description : '' ),
				'summary' => $this->xml_node_text( isset( $item->description ) ? $item->description : '' ),
				'date'    => '',
			);
		}

		$title_nodes = $xml->xpath( '/*/*[local-name()="channel"]/*[local-name()="title"]' );
		$title       = ! empty( $title_nodes ) ? $this->xml_node_text( $title_nodes[0] ) : $url;

		return array(
			'title' => $title,
			'items' => $items,
		);
	}

	/**
	 * Extracts an Atom entry link.
	 *
	 * @param \SimpleXMLElement $entry Atom entry.
	 * @return string
	 */
	private function atom_entry_link( \SimpleXMLElement $entry ) {
		foreach ( $entry->link as $link ) {
			$attributes = $link->attributes();
			$rel        = isset( $attributes['rel'] ) ? (string) $attributes['rel'] : 'alternate';

			if ( 'alternate' === $rel && isset( $attributes['href'] ) ) {
				return trim( (string) $attributes['href'] );
			}
		}

		return '';
	}

	/**
	 * Returns text content from an XML node.
	 *
	 * @param mixed $node XML node or scalar.
	 * @return string
	 */
	private function xml_node_text( $node ) {
		return trim( html_entity_decode( (string) $node, ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * Extracts one quoted or unquoted attribute value from an HTML tag.
	 *
	 * @param string $tag  HTML tag.
	 * @param string $name Attribute name.
	 * @return string|null
	 */
	private function attribute_from_tag( $tag, $name ) {
		if ( preg_match( '#\s' . preg_quote( $name, '#' ) . '\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))#i', $tag, $matches ) ) {
			foreach ( array( 2, 3, 4 ) as $index ) {
				if ( isset( $matches[ $index ] ) && '' !== $matches[ $index ] ) {
					return $matches[ $index ];
				}
			}
		}

		return null;
	}

	/**
	 * Appends a unique REST candidate after resolving it against the source URL.
	 *
	 * @param array<int,array{url:string,source:string}> $candidates Candidates.
	 * @param string                                     $url        Candidate URL.
	 * @param string                                     $source     Discovery source.
	 * @param string                                     $base_url   Source page URL.
	 * @return array<int,array{url:string,source:string}>
	 */
	private function append_unique_rest_candidate( array $candidates, $url, $source, $base_url ) {
		$url = $this->absolute_url( trim( (string) $url ), $base_url );

		if ( '' === $url || ! $this->is_remote_http_source( $url ) || ! $this->same_url_host( $url, $base_url ) ) {
			return $candidates;
		}

		foreach ( $candidates as $candidate ) {
			if ( $candidate['url'] === $url ) {
				return $candidates;
			}
		}

		$candidates[] = array(
			'url'    => $url,
			'source' => $source,
		);

		return $candidates;
	}

	/**
	 * Checks that a discovered REST API root belongs to the original source host.
	 *
	 * @param string $url      Candidate URL.
	 * @param string $base_url Source page URL.
	 * @return bool
	 */
	private function same_url_host( $url, $base_url ) {
		$url_parts  = $this->parse_url_compat( $url );
		$base_parts = $this->parse_url_compat( $base_url );

		if ( ! is_array( $url_parts ) || ! is_array( $base_parts ) || empty( $url_parts['host'] ) || empty( $base_parts['host'] ) ) {
			return false;
		}

		return strtolower( (string) $url_parts['host'] ) === strtolower( (string) $base_parts['host'] );
	}

	/**
	 * Resolves a possibly relative URL against a source URL.
	 *
	 * @param string $url      Candidate URL.
	 * @param string $base_url Base URL.
	 * @return string
	 */
	private function absolute_url( $url, $base_url ) {
		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}

		$base = $this->parse_url_compat( $base_url );

		if ( ! is_array( $base ) || empty( $base['host'] ) ) {
			return '';
		}

		$scheme = isset( $base['scheme'] ) ? strtolower( $base['scheme'] ) : 'https';
		$host   = strtolower( $base['host'] );
		$port   = isset( $base['port'] ) ? ':' . (int) $base['port'] : '';

		if ( 0 === strpos( $url, '//' ) ) {
			return $scheme . ':' . $url;
		}

		if ( 0 === strpos( $url, '/' ) ) {
			return $scheme . '://' . $host . $port . $url;
		}

		$path = isset( $base['path'] ) ? (string) $base['path'] : '/';
		$dir  = '/' . trim( dirname( $path ), '/\\' );

		return $scheme . '://' . $host . $port . rtrim( $dir, '/' ) . '/' . $url;
	}

	/**
	 * Builds a query string while keeping WordPress rest_route slashes readable.
	 *
	 * @param array<string,mixed> $query Query arguments.
	 * @return string
	 */
	private function build_query_string( array $query ) {
		$pairs = array();

		foreach ( $query as $key => $value ) {
			$raw_key = (string) $key;
			$key     = rawurlencode( $raw_key );
			$value   = str_replace( '%3A', ':', rawurlencode( (string) $value ) );
			$value   = str_replace( '%2C', ',', $value );
			$value   = 'rest_route' === $raw_key ? str_replace( '%2F', '/', $value ) : $value;
			$pairs[] = $key . '=' . $value;
		}

		return implode( '&', $pairs );
	}

	/**
	 * Finds featured media information from an embedded REST response or media entity fallback.
	 *
	 * @param ImportSession       $session Session.
	 * @param ImportSourceItem    $root    Root source item.
	 * @param array<string,mixed> $item    REST item.
	 * @return array{id:int,source_url:string,alt_text:string}|null
	 * @throws ImportRemoteRateLimitException When the remote server asks the importer to retry later.
	 */
	private function featured_media_from_rest_item( ImportSession $session, ImportSourceItem $root, array $item ) {
		$media = $this->featured_media_from_embedded_item( $item );

		if ( null !== $media ) {
			return $media;
		}

		$media_id = isset( $item['featured_media'] ) ? (int) $item['featured_media'] : 0;

		if ( $media_id < 1 ) {
			return null;
		}

		$metadata = $root->get_metadata();

		if ( empty( $metadata['remote_rest_url'] ) ) {
			return null;
		}

		try {
			$entity = $this->fetcher->fetch_json( $this->rest_media_url( $metadata['remote_rest_url'], $media_id ) );
		} catch ( ImportRemoteRateLimitException $exception ) {
			throw $exception;
		} catch ( RuntimeException $exception ) {
			$this->record_event(
				$session,
				'remote.featured_media_unavailable',
				'Remote REST featured media entity could not be fetched.',
				$root,
				array(
					'remote_media_id' => $media_id,
					'error'           => $exception->getMessage(),
				)
			);
			return null;
		}

		return is_array( $entity ) ? $this->featured_media_from_entity( $entity, $media_id ) : null;
	}

	/**
	 * Extracts featured media from a REST item _embedded payload.
	 *
	 * @param array<string,mixed> $item REST item.
	 * @return array{id:int,source_url:string,alt_text:string}|null
	 */
	private function featured_media_from_embedded_item( array $item ) {
		if ( empty( $item['_embedded'] ) || ! is_array( $item['_embedded'] ) || empty( $item['_embedded']['wp:featuredmedia'] ) || ! is_array( $item['_embedded']['wp:featuredmedia'] ) ) {
			return null;
		}

		foreach ( $item['_embedded']['wp:featuredmedia'] as $entity ) {
			if ( ! is_array( $entity ) ) {
				continue;
			}

			$media = $this->featured_media_from_entity( $entity, isset( $item['featured_media'] ) ? (int) $item['featured_media'] : 0 );

			if ( null !== $media ) {
				return $media;
			}
		}

		return null;
	}

	/**
	 * Extracts a usable source URL from one REST media entity.
	 *
	 * @param array<string,mixed> $entity      REST media entity.
	 * @param int                 $fallback_id Fallback attachment id.
	 * @return array{id:int,source_url:string,alt_text:string}|null
	 */
	private function featured_media_from_entity( array $entity, $fallback_id ) {
		$source_url = isset( $entity['source_url'] ) && is_string( $entity['source_url'] ) ? trim( $entity['source_url'] ) : '';

		if ( '' === $source_url && isset( $entity['media_details'] ) && is_array( $entity['media_details'] ) && isset( $entity['media_details']['sizes'] ) && is_array( $entity['media_details']['sizes'] ) ) {
			foreach ( array( 'full', 'large', 'medium' ) as $size ) {
				if ( isset( $entity['media_details']['sizes'][ $size ] ) && is_array( $entity['media_details']['sizes'][ $size ] ) && isset( $entity['media_details']['sizes'][ $size ]['source_url'] ) ) {
					$source_url = trim( (string) $entity['media_details']['sizes'][ $size ]['source_url'] );
					break;
				}
			}
		}

		if ( '' === $source_url || ! preg_match( '#^https?://#i', $source_url ) ) {
			return null;
		}

		return array(
			'id'         => isset( $entity['id'] ) ? (int) $entity['id'] : (int) $fallback_id,
			'source_url' => $source_url,
			'alt_text'   => isset( $entity['alt_text'] ) ? trim( $this->strip_all_tags( (string) $entity['alt_text'] ) ) : '',
		);
	}

	/**
	 * Extracts author and taxonomy relationship metadata from a REST item.
	 *
	 * @param array<string,mixed> $item REST item.
	 * @return array<string,mixed>
	 */
	private function rest_relationship_metadata( array $item ) {
		$metadata  = array();
		$author_id = isset( $item['author'] ) ? (int) $item['author'] : 0;

		if ( $author_id > 0 ) {
			$metadata['remote_author_id'] = $author_id;
		}

		$embedded = isset( $item['_embedded'] ) && is_array( $item['_embedded'] ) ? $item['_embedded'] : array();

		if ( isset( $embedded['author'] ) && is_array( $embedded['author'] ) ) {
			$author = $this->first_embedded_entity( $embedded['author'] );

			if ( null !== $author ) {
				$metadata['remote_author']    = $this->normalize_author_entity( $author, $author_id );
				$metadata['remote_author_id'] = $metadata['remote_author']['id'];
			}
		}

		$terms = $this->terms_from_embedded_item( $embedded );

		if ( ! empty( $terms ) ) {
			$metadata['remote_terms'] = $terms;
		}

		$term_ids = $this->term_ids_from_rest_item( $item, $terms );

		if ( ! empty( $term_ids ) ) {
			$metadata['remote_term_ids'] = $term_ids;
		}

		return $metadata;
	}

	/**
	 * Returns the first embedded entity from a REST relation list.
	 *
	 * @param mixed $entities Embedded relation value.
	 * @return array<string,mixed>|null
	 */
	private function first_embedded_entity( $entities ) {
		if ( ! is_array( $entities ) ) {
			return null;
		}

		foreach ( $entities as $entity ) {
			if ( is_array( $entity ) ) {
				return $entity;
			}
		}

		return null;
	}

	/**
	 * Normalizes an embedded author entity for durable staging.
	 *
	 * @param array<string,mixed> $entity      Author entity.
	 * @param int                 $fallback_id Fallback author id.
	 * @return array{id:int,name:string,slug:string,link:string}
	 */
	private function normalize_author_entity( array $entity, $fallback_id ) {
		return array(
			'id'   => isset( $entity['id'] ) ? (int) $entity['id'] : (int) $fallback_id,
			'name' => isset( $entity['name'] ) ? trim( $this->strip_all_tags( (string) $entity['name'] ) ) : '',
			'slug' => isset( $entity['slug'] ) ? $this->sanitize_key_compat( (string) $entity['slug'] ) : '',
			'link' => isset( $entity['link'] ) && is_string( $entity['link'] ) ? trim( $entity['link'] ) : '',
		);
	}

	/**
	 * Normalizes embedded term entities grouped by taxonomy.
	 *
	 * @param array<string,mixed> $embedded Embedded REST payload.
	 * @return array<string,array<int,array{id:int,name:string,slug:string,link:string}>>
	 */
	private function terms_from_embedded_item( array $embedded ) {
		if ( empty( $embedded['wp:term'] ) || ! is_array( $embedded['wp:term'] ) ) {
			return array();
		}

		$terms = array();

		foreach ( $embedded['wp:term'] as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}

			foreach ( $group as $entity ) {
				if ( ! is_array( $entity ) ) {
					continue;
				}

				$taxonomy = isset( $entity['taxonomy'] ) ? $this->sanitize_key_compat( (string) $entity['taxonomy'] ) : '';
				$id       = isset( $entity['id'] ) ? (int) $entity['id'] : 0;

				if ( '' === $taxonomy || $id < 1 ) {
					continue;
				}

				if ( ! isset( $terms[ $taxonomy ] ) ) {
					$terms[ $taxonomy ] = array();
				}

				$terms[ $taxonomy ][] = array(
					'id'   => $id,
					'name' => isset( $entity['name'] ) ? trim( $this->strip_all_tags( (string) $entity['name'] ) ) : '',
					'slug' => isset( $entity['slug'] ) ? $this->sanitize_key_compat( (string) $entity['slug'] ) : '',
					'link' => isset( $entity['link'] ) && is_string( $entity['link'] ) ? trim( $entity['link'] ) : '',
				);
			}
		}

		return $terms;
	}

	/**
	 * Extracts taxonomy id references from REST item fields and embedded terms.
	 *
	 * @param array<string,mixed>                                                        $item  REST item.
	 * @param array<string,array<int,array{id:int,name:string,slug:string,link:string}>> $terms Embedded terms.
	 * @return array<string,array<int,int>>
	 */
	private function term_ids_from_rest_item( array $item, array $terms ) {
		$term_ids = array();

		foreach ( $terms as $taxonomy => $entities ) {
			foreach ( $entities as $entity ) {
				$term_ids[ $taxonomy ][] = (int) $entity['id'];
			}
		}

		foreach ( array( 'categories', 'tags' ) as $field ) {
			if ( empty( $item[ $field ] ) || ! is_array( $item[ $field ] ) ) {
				continue;
			}

			foreach ( $item[ $field ] as $id ) {
				$id = (int) $id;

				if ( $id < 1 ) {
					continue;
				}

				if ( ! isset( $term_ids[ $field ] ) ) {
					$term_ids[ $field ] = array();
				}

				if ( ! in_array( $id, $term_ids[ $field ], true ) ) {
					$term_ids[ $field ][] = $id;
				}
			}
		}

		return $term_ids;
	}

	/**
	 * Appends a bounded REST pagination diagnostic.
	 *
	 * @param array<int,array<string,mixed>> $warnings Existing warnings.
	 * @param string                         $endpoint Endpoint name.
	 * @param int                            $page     Page number.
	 * @param string                         $error    Error message.
	 * @return array<int,array<string,mixed>>
	 */
	private function append_remote_rest_page_warning( array $warnings, $endpoint, $page, $error ) {
		$warnings[] = array(
			'endpoint' => trim( (string) $endpoint, '/' ),
			'page'     => max( 1, (int) $page ),
			'error'    => (string) $error,
		);

		return array_slice( $warnings, -10 );
	}

	/**
	 * Builds root metadata for a retryable remote rate limit.
	 *
	 * @param array<string,mixed>            $metadata  Existing metadata.
	 * @param ImportRemoteRateLimitException $exception Rate-limit exception.
	 * @return array<string,mixed>
	 */
	private function rate_limited_metadata( array $metadata, ImportRemoteRateLimitException $exception ) {
		$retry_at = time() + $exception->get_retry_after_seconds();

		$metadata['remote_status']              = 'rate-limited';
		$metadata['remote_rate_limit']          = array(
			'url'                 => $exception->get_url(),
			'status_code'         => $exception->get_status_code(),
			'retry_after_header'  => $exception->get_retry_after_header(),
			'retry_after_seconds' => $exception->get_retry_after_seconds(),
			'next_retry_at'       => gmdate( 'c', $retry_at ),
			'next_retry_unix'     => $retry_at,
		);
		$metadata['remote_rate_limit_warnings'] = $this->append_remote_rate_limit_warning(
			isset( $metadata['remote_rate_limit_warnings'] ) && is_array( $metadata['remote_rate_limit_warnings'] ) ? $metadata['remote_rate_limit_warnings'] : array(),
			$metadata['remote_rate_limit']
		);

		return $metadata;
	}

	/**
	 * Returns a traversal summary when a stored backoff window is still active.
	 *
	 * @param array<string,mixed> $metadata Source item metadata.
	 * @return array{discovered:int,queued:int,failed:int,complete:bool,message:string}|null
	 */
	private function deferred_rate_limit_summary( array $metadata ) {
		if ( empty( $metadata['remote_rate_limit'] ) || ! is_array( $metadata['remote_rate_limit'] ) || empty( $metadata['remote_rate_limit']['next_retry_unix'] ) ) {
			return null;
		}

		$next_retry = (int) $metadata['remote_rate_limit']['next_retry_unix'];

		if ( $next_retry <= time() ) {
			return null;
		}

		return array(
			'discovered' => 0,
			'queued'     => 0,
			'failed'     => 0,
			'complete'   => false,
			'message'    => 'Remote source is rate limited; next retry is scheduled for ' . gmdate( 'c', $next_retry ) . '.',
		);
	}

	/**
	 * Clears stale active backoff state while keeping bounded warning history.
	 *
	 * @param array<string,mixed> $metadata Source item metadata.
	 * @return array<string,mixed>
	 */
	private function clear_expired_rate_limit( array $metadata ) {
		if ( ! empty( $metadata['remote_rate_limit'] ) && is_array( $metadata['remote_rate_limit'] ) && ! empty( $metadata['remote_rate_limit']['next_retry_unix'] ) && (int) $metadata['remote_rate_limit']['next_retry_unix'] <= time() ) {
			unset( $metadata['remote_rate_limit'] );
			$metadata['remote_status'] = 'partial';
		}

		return $metadata;
	}

	/**
	 * Appends a bounded rate-limit diagnostic history item.
	 *
	 * @param array<int,array<string,mixed>> $warnings Existing warnings.
	 * @param array<string,mixed>            $warning  New warning.
	 * @return array<int,array<string,mixed>>
	 */
	private function append_remote_rate_limit_warning( array $warnings, array $warning ) {
		$warnings[] = $warning;

		return array_slice( $warnings, -10 );
	}

	/**
	 * Checks whether a decoded JSON array is a list suitable for REST collections.
	 *
	 * @param array<string,mixed>|array<int,mixed> $value Decoded JSON array.
	 * @return bool
	 */
	private function is_list_array( array $value ) {
		$index = 0;

		foreach ( array_keys( $value ) as $key ) {
			if ( $key !== $index ) {
				return false;
			}

			++$index;
		}

		return true;
	}

	/**
	 * Describes a decoded JSON payload shape for diagnostics without storing the full body.
	 *
	 * @param array<string,mixed>|array<int,mixed> $value Decoded JSON array.
	 * @return string
	 */
	private function describe_payload_shape( array $value ) {
		if ( $this->is_list_array( $value ) ) {
			return 'list';
		}

		return 'object:' . implode( ',', array_slice( array_map( 'strval', array_keys( $value ) ), 0, 5 ) );
	}

	/**
	 * Appends a REST post comment queue entry once.
	 *
	 * @param array<int,array<string,mixed>> $queue    Existing queue.
	 * @param string                         $endpoint REST endpoint.
	 * @param array<string,mixed>            $item     REST item.
	 * @param string                         $item_key Prepared document key.
	 * @return array<int,array<string,mixed>>
	 */
	private function append_remote_comment_queue_entry( array $queue, $endpoint, array $item, $item_key ) {
		$remote_id = isset( $item['id'] ) ? (int) $item['id'] : 0;

		if ( $remote_id < 1 ) {
			return $queue;
		}

		foreach ( $queue as $entry ) {
			if ( is_array( $entry ) && isset( $entry['source_item_key'] ) && (string) $entry['source_item_key'] === (string) $item_key ) {
				return $queue;
			}
		}

		$queue[] = array(
			'source_item_key'      => (string) $item_key,
			'remote_rest_endpoint' => trim( (string) $endpoint, '/' ),
			'remote_rest_id'       => $remote_id,
			'remote_source_url'    => isset( $item['link'] ) && is_string( $item['link'] ) ? trim( $item['link'] ) : '',
		);

		return $queue;
	}

	/**
	 * Marks comment staging complete for one prepared document.
	 *
	 * @param ImportSession          $session  Session.
	 * @param ImportSourceItem       $root     Root source item.
	 * @param ImportPreparedDocument $document Prepared document.
	 * @param array<string,mixed>    $entry    Comment queue entry.
	 * @param int                    $page     Current comment page.
	 * @param string|null            $error    Optional error message.
	 * @return void
	 */
	private function mark_rest_comments_complete_for_document( ImportSession $session, ImportSourceItem $root, ImportPreparedDocument $document, array $entry, $page, $error ) {
		$document_metadata                                   = $document->get_metadata();
		$document_metadata['remote_comments_complete']       = true;
		$document_metadata['remote_comments_completed_page'] = (int) $page;

		if ( null !== $error ) {
			$document_metadata['remote_comments_error'] = $error;
		}

		if ( ! isset( $document_metadata['remote_comments'] ) || ! is_array( $document_metadata['remote_comments'] ) ) {
			$document_metadata['remote_comments'] = array();
		}

		$document_metadata['remote_comment_count'] = count( $document_metadata['remote_comments'] );
		$this->store->save_prepared_document( $document->with_metadata( $document_metadata ) );
		$this->update_comment_source_item_metadata( $session, $document->get_source_item_key(), $document_metadata['remote_comment_count'], true, $error );
		$this->record_event(
			$session,
			null === $error ? 'remote.wp_rest_comments_complete' : 'remote.wp_rest_comments_unavailable',
			null === $error ? 'Remote WordPress REST comments are staged for a prepared document.' : 'Remote WordPress REST comments could not be fetched for a prepared document; post import can continue.',
			$root,
			array(
				'source_item_key' => $document->get_source_item_key(),
				'remote_rest_id'  => isset( $entry['remote_rest_id'] ) ? (int) $entry['remote_rest_id'] : null,
				'page'            => (int) $page,
				'comments'        => $document_metadata['remote_comment_count'],
				'error'           => $error,
			)
		);
	}

	/**
	 * Normalizes one public REST comment record for durable staging.
	 *
	 * @param array<string,mixed> $comment REST comment.
	 * @param array<string,mixed> $entry   Parent document comment queue entry.
	 * @return array<string,mixed>|null
	 */
	private function normalize_comment_entity( array $comment, array $entry ) {
		$id = isset( $comment['id'] ) ? (int) $comment['id'] : 0;

		if ( $id < 1 ) {
			return null;
		}

		$content = trim( $this->strip_scripts( $this->rendered_value( isset( $comment['content'] ) ? $comment['content'] : array(), '' ) ) );

		return array(
			'id'                   => $id,
			'remote_comment_id'    => $id,
			'remote_post_id'       => isset( $comment['post'] ) ? (int) $comment['post'] : ( isset( $entry['remote_rest_id'] ) ? (int) $entry['remote_rest_id'] : 0 ),
			'remote_parent_id'     => isset( $comment['parent'] ) ? (int) $comment['parent'] : 0,
			'source_item_key'      => isset( $entry['source_item_key'] ) ? (string) $entry['source_item_key'] : '',
			'remote_rest_endpoint' => isset( $entry['remote_rest_endpoint'] ) ? (string) $entry['remote_rest_endpoint'] : '',
			'author_id'            => isset( $comment['author'] ) ? (int) $comment['author'] : 0,
			'author_name'          => isset( $comment['author_name'] ) ? trim( $this->strip_all_tags( (string) $comment['author_name'] ) ) : '',
			'author_url'           => isset( $comment['author_url'] ) && is_string( $comment['author_url'] ) ? trim( $comment['author_url'] ) : '',
			'content'              => $content,
			'date'                 => isset( $comment['date'] ) && is_string( $comment['date'] ) ? trim( $comment['date'] ) : '',
			'date_gmt'             => isset( $comment['date_gmt'] ) && is_string( $comment['date_gmt'] ) ? trim( $comment['date_gmt'] ) : '',
			'link'                 => isset( $comment['link'] ) && is_string( $comment['link'] ) ? trim( $comment['link'] ) : '',
			'status'               => isset( $comment['status'] ) ? $this->sanitize_key_compat( (string) $comment['status'] ) : '',
			'type'                 => isset( $comment['type'] ) ? $this->sanitize_key_compat( (string) $comment['type'] ) : 'comment',
		);
	}

	/**
	 * Merges newly staged comments without duplicating remote comment ids.
	 *
	 * @param array<int,array<string,mixed>> $existing Existing comments.
	 * @param array<int,array<string,mixed>> $incoming Incoming comments.
	 * @return array<int,array<string,mixed>>
	 */
	private function merge_remote_comments( array $existing, array $incoming ) {
		$merged = array();
		$seen   = array();

		foreach ( array_merge( $existing, $incoming ) as $comment ) {
			if ( ! is_array( $comment ) || empty( $comment['remote_comment_id'] ) ) {
				continue;
			}

			$key = (string) (int) $comment['remote_comment_id'];

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$merged[]     = $comment;
		}

		return $merged;
	}

	/**
	 * Mirrors staged comment counts onto the imported source item metadata.
	 *
	 * @param ImportSession $session         Session.
	 * @param string        $source_item_key Source item key.
	 * @param int           $count           Staged comment count.
	 * @param bool          $complete        Whether staging is complete for this item.
	 * @param string|null   $error           Optional comment fetch error.
	 * @return void
	 */
	private function update_comment_source_item_metadata( ImportSession $session, $source_item_key, $count, $complete, $error ) {
		$item = $this->store->find_source_item( $session->get_id(), $source_item_key );

		if ( null === $item ) {
			return;
		}

		$metadata                             = $item->get_metadata();
		$metadata['remote_comment_count']     = (int) $count;
		$metadata['remote_comments_complete'] = (bool) $complete;

		if ( null !== $error ) {
			$metadata['remote_comments_error'] = $error;
		}

		$this->store->save_source_item( $item->with_metadata( $metadata ) );
	}

	/**
	 * Sanitizes a REST key using WordPress when available.
	 *
	 * @param string $key Raw key.
	 * @return string
	 */
	private function sanitize_key_compat( $key ) {
		if ( function_exists( 'sanitize_key' ) ) {
			return sanitize_key( $key );
		}

		return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) );
	}

	/**
	 * Builds a WordPress image block for featured media.
	 *
	 * @param array{id:int,source_url:string,alt_text:string}|null $featured_media Featured media.
	 * @return string
	 */
	private function featured_media_block_markup( $featured_media ) {
		if ( null === $featured_media ) {
			return '';
		}

		$url = $this->esc_url_compat( $featured_media['source_url'] );
		$alt = $this->esc_attr_compat( $featured_media['alt_text'] );

		return '<!-- wp:image {"url":"' . $url . '"} -->' . "\n"
			. '<figure class="wp-block-image"><img src="' . $url . '" alt="' . $alt . '"/></figure>' . "\n"
			. '<!-- /wp:image -->' . "\n\n";
	}

	/**
	 * Checks whether a wp/v2/types entry is suitable for document import.
	 *
	 * @param string              $slug Post type slug.
	 * @param array<string,mixed> $type Type response.
	 * @return bool
	 */
	private function is_importable_rest_type( $slug, array $type ) {
		if ( isset( $type['viewable'] ) && false === (bool) $type['viewable'] ) {
			return false;
		}

		$rest_base = isset( $type['rest_base'] ) && is_string( $type['rest_base'] ) ? trim( $type['rest_base'], '/' ) : trim( (string) $slug, '/' );

		if ( '' === $rest_base ) {
			return false;
		}

		return ! in_array(
			$rest_base,
			array(
				'media',
				'blocks',
				'templates',
				'template-parts',
				'navigation',
				'font-families',
				'font-faces',
			),
			true
		);
	}

	/**
	 * Appends a REST endpoint while preserving first-seen order.
	 *
	 * @param array<int,string> $endpoints Existing endpoint list.
	 * @param string            $endpoint  Endpoint to append.
	 * @return array<int,string>
	 */
	private function append_unique_endpoint( array $endpoints, $endpoint ) {
		$endpoint = trim( (string) $endpoint, '/' );

		if ( '' !== $endpoint && ! in_array( $endpoint, $endpoints, true ) ) {
			$endpoints[] = $endpoint;
		}

		return $endpoints;
	}

	/**
	 * Keeps pages/posts first while preserving discovered custom collection order.
	 *
	 * @param array<int,string> $endpoints Discovered endpoints.
	 * @return array<int,string>
	 */
	private function prioritize_default_rest_endpoints( array $endpoints ) {
		$ordered = array();

		foreach ( self::$default_rest_endpoints as $endpoint ) {
			if ( in_array( $endpoint, $endpoints, true ) ) {
				$ordered[] = $endpoint;
			}
		}

		foreach ( $endpoints as $endpoint ) {
			$ordered = $this->append_unique_endpoint( $ordered, $endpoint );
		}

		return $ordered;
	}

	/**
	 * Returns a rendered REST field value.
	 *
	 * @param mixed  $value    REST field.
	 * @param string $fallback Fallback value.
	 * @return string
	 */
	private function rendered_value( $value, $fallback ) {
		if ( is_array( $value ) && isset( $value['rendered'] ) && '' !== trim( (string) $value['rendered'] ) ) {
			return trim( (string) $value['rendered'] );
		}

		return $fallback;
	}

	/**
	 * Extracts a body fragment from an HTML response.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private function extract_html_body( $html ) {
		return ( new ImportHtmlBlockConverter() )->extract_body( $html );
	}

	/**
	 * Extracts an HTML title fallback.
	 *
	 * @param string $html HTML response.
	 * @param string $url  Source URL.
	 * @return string
	 */
	private function title_from_html( $html, $url ) {
		if ( preg_match( '#<title\b[^>]*>(.*?)</title>#is', (string) $html, $matches ) ) {
			$title = trim( html_entity_decode( $this->strip_all_tags( $matches[1] ), ENT_QUOTES, 'UTF-8' ) );

			if ( '' !== $title ) {
				return $title;
			}
		}

		$parts = $this->parse_url_compat( $url );
		$path  = isset( $parts['path'] ) ? $parts['path'] : '';
		$name  = is_string( $path ) && '' !== trim( $path, '/' ) ? basename( trim( $path, '/' ) ) : ( isset( $parts['host'] ) ? $parts['host'] : '' );

		return '' === trim( (string) $name ) ? 'Remote URL' : trim( (string) $name );
	}

	/**
	 * Normalizes feed item content to an HTML fragment.
	 *
	 * @param string $content Feed item content.
	 * @return string
	 */
	private function feed_content_html_fragment( $content ) {
		$content = trim( (string) $content );

		if ( '' === $content ) {
			return '';
		}

		if ( false !== strpos( $content, '<' ) && false !== strpos( $content, '>' ) ) {
			return $content;
		}

		return '<p>' . htmlspecialchars( $content, ENT_QUOTES, 'UTF-8' ) . '</p>';
	}

	/**
	 * Builds a compact visible path for a feed item source.
	 *
	 * @param string $source Source URL.
	 * @param int    $index  Feed item index.
	 * @return string
	 */
	private function feed_item_relative_path( $source, $index ) {
		$parts = $this->parse_url_compat( $source );

		if ( is_array( $parts ) && ! empty( $parts['path'] ) ) {
			$path = trim( (string) $parts['path'], '/' );

			if ( '' !== $path ) {
				return $path;
			}
		}

		return 'feed-item-' . ( (int) $index + 1 );
	}

	/**
	 * Removes script blocks from imported content.
	 *
	 * @param string $content Source content.
	 * @return string
	 */
	private function strip_scripts( $content ) {
		return ( new ImportHtmlBlockConverter() )->strip_scripts( $content );
	}

	/**
	 * Counts WordPress block delimiters in markup.
	 *
	 * @param string $markup Block markup.
	 * @return int
	 */
	private function count_blocks( $markup ) {
		preg_match_all( '/<!--\s+wp:/', $markup, $matches );

		return count( $matches[0] );
	}

	/**
	 * Strips all tags using WordPress when available.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private function strip_all_tags( $html ) {
		if ( function_exists( 'wp_strip_all_tags' ) ) {
			return wp_strip_all_tags( $html );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Unit tests run without WordPress loaded.
		return strip_tags( $html );
	}

	/**
	 * Escapes a URL using WordPress when available.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function esc_url_compat( $url ) {
		if ( function_exists( 'esc_url' ) ) {
			return esc_url( $url );
		}

		return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Escapes an HTML attribute using WordPress when available.
	 *
	 * @param string $value Attribute value.
	 * @return string
	 */
	private function esc_attr_compat( $value ) {
		if ( function_exists( 'esc_attr' ) ) {
			return esc_attr( $value );
		}

		return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Parses a URL using WordPress when available.
	 *
	 * @param string $url URL.
	 * @return array<string,mixed>|false
	 */
	private function parse_url_compat( $url ) {
		if ( function_exists( 'wp_parse_url' ) ) {
			return wp_parse_url( $url );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Unit tests run without WordPress loaded.
		return parse_url( $url );
	}

	/**
	 * Rebuilds a URL from parse_url parts.
	 *
	 * @param array<string,mixed> $parts          URL parts.
	 * @param string|null         $query_override Optional replacement query string.
	 * @return string
	 */
	private function unparse_url_compat( array $parts, $query_override = null ) {
		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) . '://' : '';
		$user   = isset( $parts['user'] ) ? (string) $parts['user'] : '';
		$pass   = isset( $parts['pass'] ) ? ':' . (string) $parts['pass'] : '';
		$auth   = '' === $user ? '' : $user . $pass . '@';
		$host   = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
		$port   = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path   = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		$query  = null === $query_override && isset( $parts['query'] ) ? '?' . (string) $parts['query'] : ( null === $query_override || '' === $query_override ? '' : '?' . $query_override );

		return $scheme . $auth . $host . $port . $path . $query;
	}

	/**
	 * Extracts normalized hostnames from absolute HTTP(S) URLs.
	 *
	 * @param string $content Content.
	 * @return array<string,array<int,string>>
	 */
	private function extract_absolute_url_domains( $content ) {
		$domains = array();

		if ( ! preg_match_all( '#https?://([^/\s<>"\'\)\]\}:]+)(?::\d+)?[^\s<>"\'\)\]\}]*#i', (string) $content, $matches, PREG_SET_ORDER ) ) {
			return $domains;
		}

		foreach ( $matches as $match ) {
			$url  = rtrim( html_entity_decode( $match[0], ENT_QUOTES, 'UTF-8' ), '.,;:' );
			$host = strtolower( trim( preg_replace( '/:\d+$/', '', $match[1] ) ) );

			if ( '' === $host ) {
				continue;
			}

			if ( ! isset( $domains[ $host ] ) ) {
				$domains[ $host ] = array();
			}

			if ( count( $domains[ $host ] ) < 3 && ! in_array( $url, $domains[ $host ], true ) ) {
				$domains[ $host ][] = $url;
			}
		}

		ksort( $domains );

		return $domains;
	}

	/**
	 * Encodes structured data for stable hashes.
	 *
	 * @param array<string,mixed>|array<int,mixed> $data Data.
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
	 * Records a remote traversal event.
	 *
	 * @param ImportSession       $session Session.
	 * @param string              $type    Event type.
	 * @param string              $message Event message.
	 * @param ImportSourceItem    $item    Source item.
	 * @param array<string,mixed> $context Context.
	 * @return void
	 */
	private function record_event( ImportSession $session, $type, $message, ImportSourceItem $item, array $context ) {
		$level = ImportProgressEvent::LEVEL_INFO;

		if ( 0 === strpos( $type, 'remote.failed' ) ) {
			$level = ImportProgressEvent::LEVEL_ERROR;
		} elseif ( false !== strpos( $type, '_unavailable' ) || false !== strpos( $type, 'rate_limited' ) ) {
			$level = ImportProgressEvent::LEVEL_WARNING;
		}

		$this->store->record_event(
			$session->get_id(),
			new ImportProgressEvent(
				$level,
				$type,
				$message,
				array_merge(
					array(
						'item_key'      => $item->get_key(),
						'relative_path' => $item->get_relative_path(),
					),
					$context
				)
			)
		);
	}
}
