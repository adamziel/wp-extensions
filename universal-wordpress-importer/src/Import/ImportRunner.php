<?php
/**
 * Shared import continuation runner.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Coordinates one resumable import worker tick.
 */
final class ImportRunner {
	const DEFAULT_LOCK_TTL_SECONDS  = 300;
	const DEFAULT_BATCH_SIZE        = 20;
	const DEFAULT_SOURCE_ITEM_LIMIT = 100;

	/**
	 * Store used for durable session state.
	 *
	 * @var WordPressImportSessionStore
	 */
	private $store;

	/**
	 * Worker owner name recorded in session locks.
	 *
	 * @var string
	 */
	private $owner;

	/**
	 * Lock TTL in seconds.
	 *
	 * @var int
	 */
	private $lock_ttl_seconds;

	/**
	 * Hidden failure simulation controls for this tick.
	 *
	 * @var ImportRunnerControls
	 */
	private $controls;

	/**
	 * Optional post gateway override for tests.
	 *
	 * @var ImportPostGatewayInterface|null
	 */
	private $post_gateway;

	/**
	 * Optional media gateway override for tests.
	 *
	 * @var ImportMediaGatewayInterface|null
	 */
	private $media_gateway;

	/**
	 * Optional comment gateway override for tests.
	 *
	 * @var ImportCommentGatewayInterface|null
	 */
	private $comment_gateway;

	/**
	 * Optional local site URL override for tests.
	 *
	 * @var string|null
	 */
	private $local_site_url;

	/**
	 * Optional remote archive fetcher override for tests.
	 *
	 * @var ImportRemoteArchiveFetcherInterface|null
	 */
	private $remote_archive_fetcher;

	/**
	 * Optional remote content fetcher override for tests.
	 *
	 * @var ImportRemoteContentFetcherInterface|null
	 */
	private $remote_content_fetcher;

	/**
	 * Optional importer cache directory override for tests.
	 *
	 * @var ImportCacheDirectory|null
	 */
	private $cache_directory;

	/**
	 * Optional callback that schedules the next continuation tick.
	 *
	 * @var callable|null
	 */
	private $continuation_scheduler;

	/**
	 * Constructor.
	 *
	 * @param WordPressImportSessionStore              $store            Session store.
	 * @param string|null                              $owner            Optional worker owner.
	 * @param int|null                                 $lock_ttl_seconds Optional lock TTL.
	 * @param ImportRunnerControls|null                $controls         Optional hidden test controls.
	 * @param ImportPostGatewayInterface|null          $post_gateway  Optional post gateway override.
	 * @param string|null                              $local_site_url Optional local site URL override.
	 * @param ImportMediaGatewayInterface|null         $media_gateway  Optional media gateway override.
	 * @param ImportRemoteArchiveFetcherInterface|null $remote_archive_fetcher Optional remote archive fetcher.
	 * @param ImportRemoteContentFetcherInterface|null $remote_content_fetcher Optional remote content fetcher.
	 * @param ImportCommentGatewayInterface|null       $comment_gateway Optional comment gateway override.
	 * @param ImportCacheDirectory|null                $cache_directory Optional cache directory override.
	 * @param callable|null                            $continuation_scheduler Optional continuation scheduler.
	 * @throws InvalidArgumentException When the owner or lock TTL is invalid.
	 */
	public function __construct( WordPressImportSessionStore $store, $owner = null, $lock_ttl_seconds = null, ImportRunnerControls $controls = null, ImportPostGatewayInterface $post_gateway = null, $local_site_url = null, ImportMediaGatewayInterface $media_gateway = null, ImportRemoteArchiveFetcherInterface $remote_archive_fetcher = null, ImportRemoteContentFetcherInterface $remote_content_fetcher = null, ImportCommentGatewayInterface $comment_gateway = null, ImportCacheDirectory $cache_directory = null, callable $continuation_scheduler = null ) {
		$owner = null === $owner ? $this->default_owner() : trim( (string) $owner );

		if ( '' === $owner ) {
			throw new InvalidArgumentException( 'Import runner owner cannot be empty.' );
		}

		$lock_ttl_seconds = null === $lock_ttl_seconds ? self::DEFAULT_LOCK_TTL_SECONDS : (int) $lock_ttl_seconds;

		if ( $lock_ttl_seconds < 1 ) {
			throw new InvalidArgumentException( 'Import runner lock TTL must be at least one second.' );
		}

		$this->store                  = $store;
		$this->owner                  = $owner;
		$this->lock_ttl_seconds       = $lock_ttl_seconds;
		$this->controls               = null === $controls ? ImportRunnerControls::none() : $controls;
		$this->post_gateway           = $post_gateway;
		$this->media_gateway          = $media_gateway;
		$this->local_site_url         = null === $local_site_url ? null : (string) $local_site_url;
		$this->remote_archive_fetcher = $remote_archive_fetcher;
		$this->remote_content_fetcher = $remote_content_fetcher;
		$this->comment_gateway        = $comment_gateway;
		$this->cache_directory        = $cache_directory;
		$this->continuation_scheduler = $continuation_scheduler;
	}

	/**
	 * Builds a runner from WordPress globals.
	 *
	 * @param callable|null $continuation_scheduler Optional continuation scheduler.
	 * @return self
	 */
	public static function from_globals( callable $continuation_scheduler = null ) {
		return new self( WordPressImportSessionStore::from_globals(), null, null, null, null, null, null, null, null, null, null, $continuation_scheduler );
	}

	/**
	 * Runs one continuation tick.
	 *
	 * @param ImportSessionId|null $session_id Optional session id from a scheduled event.
	 * @param int                  $limit      Maximum number of sessions to inspect.
	 * @return array{processed:int,locked:int,skipped:int,errors:int}
	 */
	public function run( ImportSessionId $session_id = null, $limit = self::DEFAULT_BATCH_SIZE ) {
		$summary = array(
			'processed' => 0,
			'locked'    => 0,
			'skipped'   => 0,
			'errors'    => 0,
		);

		foreach ( $this->load_candidate_sessions( $session_id, $this->controls->get_effective_limit( $limit ) ) as $session ) {
			$result = $this->continue_session( $session );
			++$summary[ $result ];
		}

		return $summary;
	}

	/**
	 * Continues one session under an expiring lock.
	 *
	 * @param ImportSession $session Candidate session.
	 * @return string Summary bucket name.
	 */
	private function continue_session( ImportSession $session ) {
		if ( $this->is_terminal_status( $session->get_status() ) ) {
			$this->record_skipped_event(
				$session,
				'session.skipped_terminal',
				'Import session is already terminal; continuation was ignored.'
			);
			return 'skipped';
		}

		if ( ImportSession::STATUS_PAUSED === $session->get_status() || ImportSession::STATUS_FAILED === $session->get_status() ) {
			$this->record_skipped_event(
				$session,
				'session.skipped_not_runnable',
				'Import session is paused or failed; resume it before continuing.'
			);
			return 'skipped';
		}

		$lock = $this->store->acquire_lock( $session->get_id(), $this->owner, $this->lock_ttl_seconds );

		if ( null === $lock ) {
			$this->record_locked_event_once( $session );
			$this->schedule_locked_retry( $session );
			return 'locked';
		}

		try {
			$current = $this->store->find( $session->get_id() );

			if ( null === $current ) {
				return 'skipped';
			}

			if ( ImportSession::STATUS_PENDING === $current->get_status() ) {
				$current = $current->mark_running();
				$this->store->save( $current );
				$this->store->record_event(
					$current->get_id(),
					new ImportProgressEvent(
						ImportProgressEvent::LEVEL_INFO,
						'session.started',
						'Import session moved from pending to running.',
						array( 'owner' => $this->owner )
					)
				);
			}

			if ( ! $this->apply_failure_simulations( $current ) ) {
				return 'skipped';
			}

			$local              = ( new LocalFilesystemSourceWalker( $this->store ) )->advance( $current, self::DEFAULT_SOURCE_ITEM_LIMIT );
			$lock               = $this->refresh_lock( $lock );
			$github_git_fetcher = null === $this->remote_content_fetcher && null === $this->remote_archive_fetcher ? new PhpToolkitGitRepositoryFetcher() : null;
			$github             = ( new GitHubRepositorySourceWalker( $this->store, $this->remote_archive_fetcher, $this->cache_directory, null, $this->controls, $github_git_fetcher ) )->advance( $current );
			$lock               = $this->refresh_lock( $lock );
			$remote             = ( new RemoteUrlSourceWalker( $this->store, $this->remote_content_fetcher, $this->controls ) )->advance( $current );
			$lock               = $this->refresh_lock( $lock );
			$traversal          = $this->combine_traversal_summaries( $local, $github, $remote );
			$archives           = ( new ZipArchiveSourceWalker( $this->store, $this->cache_directory, $this->controls ) )->advance( $current, self::DEFAULT_SOURCE_ITEM_LIMIT );
			$lock               = $this->refresh_lock( $lock );
			$documents          = ( new SourceItemDocumentProcessor( $this->store, $this->cache_directory, $this->controls ) )->advance( $current, self::DEFAULT_SOURCE_ITEM_LIMIT );
			$lock               = $this->refresh_lock( $lock );
			$urls               = ( new ImportUrlInference( $this->store ) )->advance( $current, self::DEFAULT_SOURCE_ITEM_LIMIT );
			$lock               = $this->refresh_lock( $lock );
			$media              = $urls['blocked'] ? $this->blocked_media_summary() : ( new ImportMediaReferenceDetector( $this->store ) )->advance( $current, self::DEFAULT_SOURCE_ITEM_LIMIT );
			$lock               = $this->refresh_lock( $lock );
			if ( $current->is_dry_run() ) {
				$rewrites       = $urls['blocked'] ? $this->blocked_rewrite_summary() : ( new ImportUrlRewriter( $this->store, $this->local_site_url, true ) )->advance( $current, self::DEFAULT_SOURCE_ITEM_LIMIT );
				$lock           = $this->refresh_lock( $lock );
				$media_imports  = $this->dry_run_media_import_summary();
				$posts          = $this->dry_run_post_summary();
				$epub_links     = $this->dry_run_epub_link_summary();
				$markdown_links = $this->dry_run_markdown_link_summary();
				$postmeta       = $this->dry_run_postmeta_summary();
				$att_meta       = $this->dry_run_attachment_metadata_summary();
				$parents        = $this->dry_run_attachment_parent_summary();
				$comments       = $this->dry_run_comment_summary();
				$mappings       = $this->dry_run_mapping_summary();
				$menus          = $this->dry_run_nav_menu_summary();
				$this->record_dry_run_write_skip_event( $current, $documents, $media );
			} else {
				$media_imports  = $urls['blocked'] ? $this->blocked_media_import_summary() : ( new ImportMediaImporter( $this->store, $this->media_gateway, $this->controls ) )->advance( $current, self::DEFAULT_SOURCE_ITEM_LIMIT );
				$lock           = $this->refresh_lock( $lock );
				$rewrites       = $urls['blocked'] ? $this->blocked_rewrite_summary() : ( new ImportUrlRewriter( $this->store, $this->local_site_url, true ) )->advance( $current, self::DEFAULT_SOURCE_ITEM_LIMIT );
				$lock           = $this->refresh_lock( $lock );
				$posts          = $urls['blocked'] || $media_imports['blocked'] ? $this->blocked_post_summary( $urls['blocked'] ? $urls : $media_imports ) : ( new ImportPostPersister( $this->store, $this->post_gateway, $this->controls, true ) )->advance( $current, self::DEFAULT_SOURCE_ITEM_LIMIT );
				$lock           = $this->refresh_lock( $lock );
				$epub_links     = $urls['blocked'] || $media_imports['blocked'] ? $this->blocked_epub_link_summary( $urls['blocked'] ? $urls : $media_imports ) : ( new ImportEpubInternalLinkResolver( $this->store, $this->post_gateway ) )->advance( $current, self::DEFAULT_SOURCE_ITEM_LIMIT );
				$lock           = $this->refresh_lock( $lock );
				$markdown_links = $urls['blocked'] || $media_imports['blocked'] ? $this->blocked_markdown_link_summary( $urls['blocked'] ? $urls : $media_imports ) : ( new ImportMarkdownInternalLinkResolver( $this->store, $this->post_gateway ) )->advance( $current, self::DEFAULT_SOURCE_ITEM_LIMIT );
				if ( ( 0 < $epub_links['resolved'] || 0 < $markdown_links['resolved'] ) && ! $urls['blocked'] && ! $media_imports['blocked'] ) {
					$posts = $this->combine_post_summaries(
						$posts,
						( new ImportPostPersister( $this->store, $this->post_gateway, $this->controls, true ) )->advance( $current, self::DEFAULT_SOURCE_ITEM_LIMIT )
					);
				}
				$lock     = $this->refresh_lock( $lock );
				$postmeta = $urls['blocked'] || $media_imports['blocked'] ? $this->blocked_postmeta_summary( $urls['blocked'] ? $urls : $media_imports ) : ( new ImportPostMetaPersister( $this->store, $this->post_gateway, $this->local_site_url ) )->advance( $current, self::DEFAULT_SOURCE_ITEM_LIMIT );
				$lock     = $this->refresh_lock( $lock );
				$att_meta = $urls['blocked'] || $media_imports['blocked'] ? $this->blocked_attachment_metadata_summary( $urls['blocked'] ? $urls : $media_imports ) : ( new ImportWxrAttachmentMetadataPersister( $this->store, $this->media_gateway ) )->advance( $current, self::DEFAULT_SOURCE_ITEM_LIMIT );
				$lock     = $this->refresh_lock( $lock );
				$parents  = $urls['blocked'] || $media_imports['blocked'] ? $this->blocked_attachment_parent_summary( $urls['blocked'] ? $urls : $media_imports ) : ( new ImportWxrAttachmentParentPersister( $this->store, $this->post_gateway, $this->media_gateway ) )->advance( $current, self::DEFAULT_SOURCE_ITEM_LIMIT );
				$lock     = $this->refresh_lock( $lock );
				$comments = $urls['blocked'] || $media_imports['blocked'] ? $this->blocked_comment_summary( $urls['blocked'] ? $urls : $media_imports ) : ( new ImportCommentPersister( $this->store, $this->post_gateway, $this->comment_gateway, $this->controls ) )->advance( $current, self::DEFAULT_SOURCE_ITEM_LIMIT );
				$lock     = $this->refresh_lock( $lock );
				$mappings = ( new ImportRelationshipMappingApplier( $this->store, $this->post_gateway ) )->advance( $current, self::DEFAULT_SOURCE_ITEM_LIMIT );
				$lock     = $this->refresh_lock( $lock );
				$menus    = $urls['blocked'] || $media_imports['blocked'] ? $this->blocked_nav_menu_summary( $urls['blocked'] ? $urls : $media_imports ) : ( new ImportWxrNavMenuPersister( $this->store, $this->post_gateway, $this->local_site_url ) )->advance( $current, self::DEFAULT_SOURCE_ITEM_LIMIT );
				$lock     = $this->refresh_lock( $lock );
			}
			$previous_status = $current->get_status();
			$current         = $this->with_traversal_progress( $current, $traversal );
			$current         = $this->with_completion_status(
				$current,
				$traversal,
				$media_imports,
				$posts,
				$epub_links,
				$markdown_links,
				$postmeta,
				$att_meta,
				$parents,
				$comments,
				$mappings,
				$menus
			);
			$this->store->save( $current );

			$this->store->record_event(
				$current->get_id(),
				new ImportProgressEvent(
					ImportProgressEvent::LEVEL_INFO,
					$traversal['complete'] ? 'source.discovery_complete' : 'source.discovery_progress',
					$traversal['message'],
					array(
						'owner'          => $this->owner,
						'dry_run'        => $current->is_dry_run(),
						'discovered'     => $traversal['discovered'],
						'queued'         => $traversal['queued'],
						'failed'         => $traversal['failed'],
						'imported'       => $documents['imported'],
						'skipped'        => $documents['skipped'],
						'url_domains'    => $urls['domains'],
						'archives'       => array(
							'expanded' => $archives['expanded'],
							'queued'   => $archives['queued'],
							'failed'   => $archives['failed'],
						),
						'github'         => array(
							'queued' => $github['queued'],
							'failed' => $github['failed'],
						),
						'remote'         => array(
							'queued' => $remote['queued'],
							'failed' => $remote['failed'],
						),
						'url_rewrites'   => array(
							'rewritten' => $rewrites['rewritten'],
							'skipped'   => $rewrites['skipped'],
						),
						'media'          => array(
							'queued'    => $media['queued'],
							'imported'  => $media_imports['imported'],
							'rewritten' => $media_imports['rewritten'],
							'skipped'   => $media['skipped'] + $media_imports['skipped'],
							'failed'    => $media_imports['failed'],
						),
						'posts'          => array(
							'created' => $posts['created'],
							'updated' => $posts['updated'],
							'skipped' => $posts['skipped'],
							'failed'  => $posts['failed'],
						),
						'epub_links'     => array(
							'resolved' => $epub_links['resolved'],
							'deferred' => $epub_links['deferred'],
							'skipped'  => $epub_links['skipped'],
							'failed'   => $epub_links['failed'],
						),
						'markdown_links' => array(
							'resolved' => $markdown_links['resolved'],
							'deferred' => $markdown_links['deferred'],
							'skipped'  => $markdown_links['skipped'],
							'failed'   => $markdown_links['failed'],
						),
						'postmeta'       => array(
							'applied'  => $postmeta['applied'],
							'skipped'  => $postmeta['skipped'],
							'deferred' => $postmeta['deferred'],
							'failed'   => $postmeta['failed'],
						),
						'attachments'    => array(
							'metadata_applied'  => $att_meta['applied'],
							'metadata_skipped'  => $att_meta['skipped'],
							'metadata_deferred' => $att_meta['deferred'],
							'metadata_failed'   => $att_meta['failed'],
							'parents_applied'   => $parents['applied'],
							'parents_skipped'   => $parents['skipped'],
							'parents_deferred'  => $parents['deferred'],
							'parents_failed'    => $parents['failed'],
						),
						'comments'       => array(
							'created'  => $comments['created'],
							'updated'  => $comments['updated'],
							'skipped'  => $comments['skipped'],
							'deferred' => $comments['deferred'],
							'failed'   => $comments['failed'],
						),
						'mappings'       => array(
							'applied' => $mappings['applied'],
							'skipped' => $mappings['skipped'],
							'failed'  => $mappings['failed'],
						),
						'nav_menus'      => array(
							'applied'  => $menus['applied'],
							'skipped'  => $menus['skipped'],
							'deferred' => $menus['deferred'],
							'failed'   => $menus['failed'],
						),
					)
				)
			);

			if ( ImportSession::STATUS_DONE === $current->get_status() && ImportSession::STATUS_DONE !== $previous_status ) {
				$this->store->record_event(
					$current->get_id(),
					new ImportProgressEvent(
						ImportProgressEvent::LEVEL_INFO,
						'session.done',
						'Import session completed.',
						array(
							'owner'   => $this->owner,
							'dry_run' => $current->is_dry_run(),
						)
					)
				);
			}

			$this->schedule_next_tick_if_needed( $current );

			return 'processed';
		} catch ( Throwable $exception ) {
			$this->store->record_event(
				$session->get_id(),
				new ImportProgressEvent(
					ImportProgressEvent::LEVEL_ERROR,
					'runner.error',
					$exception->getMessage(),
					array( 'owner' => $this->owner )
				)
			);

			return 'errors';
		} finally {
			$this->store->release_lock( $lock );
		}
	}

	/**
	 * Schedules another continuation tick when durable work remains.
	 *
	 * @param ImportSession $session Current session after this tick.
	 * @return void
	 */
	private function schedule_next_tick_if_needed( ImportSession $session ) {
		if ( null === $this->continuation_scheduler || ! $this->session_has_runnable_work( $session ) ) {
			return;
		}

		call_user_func( $this->continuation_scheduler, $session->get_id() );
	}

	/**
	 * Schedules a later tick for sessions that are temporarily locked.
	 *
	 * @param ImportSession $session Locked session.
	 * @return void
	 */
	private function schedule_locked_retry( ImportSession $session ) {
		if ( null === $this->continuation_scheduler ) {
			return;
		}

		call_user_func( $this->continuation_scheduler, $session->get_id() );
	}

	/**
	 * Extends the current lock before the next bounded phase begins.
	 *
	 * @param ImportSessionLock $lock Current lock.
	 * @return ImportSessionLock Refreshed lock.
	 * @throws RuntimeException When this worker no longer owns the lock.
	 */
	private function refresh_lock( ImportSessionLock $lock ) {
		$refreshed = $this->store->refresh_lock( $lock, $this->lock_ttl_seconds );

		if ( null === $refreshed ) {
			throw new RuntimeException( 'Import session lock was lost before the tick completed.' );
		}

		return $refreshed;
	}

	/**
	 * Returns whether another cron tick can make progress without operator input.
	 *
	 * @param ImportSession $session Current session after this tick.
	 * @return bool
	 */
	private function session_has_runnable_work( ImportSession $session ) {
		if ( ImportSession::STATUS_PENDING !== $session->get_status() && ImportSession::STATUS_RUNNING !== $session->get_status() ) {
			return false;
		}

		if ( ! empty( $this->store->list_pending_decisions( $session->get_id() ) ) ) {
			return false;
		}

		if ( 0 !== $this->store->count_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_FAILED ) ) ) {
			return false;
		}

		if ( 0 !== $this->store->count_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_QUEUED, ImportSourceItem::STATUS_PROCESSING, ImportSourceItem::STATUS_DISCOVERED ) ) ) {
			return true;
		}

		if ( 0 !== $this->store->count_media_references_by_statuses( $session->get_id(), array( ImportMediaReference::STATUS_QUEUED ) ) ) {
			return true;
		}

		$prepared_documents = $this->store->count_prepared_documents( $session->get_id() );

		if (
			0 < $prepared_documents
			&& (
				$this->store->count_idempotency_records_by_resource_type( $session->get_id(), 'prepared_document' ) < $prepared_documents
				|| ( ! $session->is_dry_run() && $this->store->count_idempotency_records_by_resource_type( $session->get_id(), 'post' ) < $prepared_documents )
			)
		) {
			return true;
		}

		if ( ! $session->is_dry_run() && $this->has_unresolved_epub_internal_links( $session ) ) {
			return true;
		}

		if ( ! $session->is_dry_run() && $this->has_pending_wxr_nav_menu_work( $session ) ) {
			return true;
		}

		if ( ! $session->is_dry_run() && $this->has_pending_wxr_attachment_work( $session ) ) {
			return true;
		}

		return ! $session->is_dry_run() && $this->has_pending_remote_comments( $session );
	}

	/**
	 * Loads sessions to inspect for this tick.
	 *
	 * @param ImportSessionId|null $session_id Optional explicit session id.
	 * @param int                  $limit      Maximum queued sessions to load.
	 * @return array<int,ImportSession>
	 */
	private function load_candidate_sessions( ImportSessionId $session_id = null, $limit = self::DEFAULT_BATCH_SIZE ) {
		if ( null !== $session_id ) {
			$session = $this->store->find( $session_id );

			return null === $session ? array() : array( $session );
		}

		return $this->store->list_sessions_by_statuses(
			array(
				ImportSession::STATUS_PENDING,
				ImportSession::STATUS_RUNNING,
			),
			$limit
		);
	}

	/**
	 * Records a non-fatal skip event.
	 *
	 * @param ImportSession $session Session being skipped.
	 * @param string        $type    Event type.
	 * @param string        $message Event message.
	 * @return void
	 */
	private function record_skipped_event( ImportSession $session, $type, $message ) {
		$this->store->record_event(
			$session->get_id(),
			new ImportProgressEvent(
				ImportProgressEvent::LEVEL_WARNING,
				$type,
				$message,
				array(
					'owner'  => $this->owner,
					'status' => $session->get_status(),
				)
			)
		);
	}

	/**
	 * Records one lock collision event without flooding the activity log.
	 *
	 * @param ImportSession $session Session being skipped.
	 * @return void
	 */
	private function record_locked_event_once( ImportSession $session ) {
		$events = $this->store->list_events( $session->get_id(), 1 );

		if ( ! empty( $events ) && 'session.locked' === $events[0]->get_type() ) {
			return;
		}

		$this->record_skipped_event(
			$session,
			'session.locked',
			'Another importer worker owns this session lock.'
		);
	}

	/**
	 * Whether a status is terminal.
	 *
	 * @param string $status Session status.
	 * @return bool
	 */
	private function is_terminal_status( $status ) {
		return ImportSession::STATUS_DONE === $status || ImportSession::STATUS_ABORTED === $status;
	}

	/**
	 * Updates session progress from durable source item queue state.
	 *
	 * @param ImportSession       $session   Session.
	 * @param array<string,mixed> $traversal Traversal summary.
	 * @return ImportSession
	 */
	private function with_traversal_progress( ImportSession $session, array $traversal ) {
		$terminal_statuses = array(
			ImportSourceItem::STATUS_DISCOVERED,
			ImportSourceItem::STATUS_IMPORTED,
			ImportSourceItem::STATUS_SKIPPED,
			ImportSourceItem::STATUS_FAILED,
		);
		$all_statuses      = array(
			ImportSourceItem::STATUS_QUEUED,
			ImportSourceItem::STATUS_PROCESSING,
			ImportSourceItem::STATUS_DISCOVERED,
			ImportSourceItem::STATUS_IMPORTED,
			ImportSourceItem::STATUS_SKIPPED,
			ImportSourceItem::STATUS_FAILED,
		);

		$total     = $this->store->count_source_items_by_statuses( $session->get_id(), $all_statuses );
		$completed = $this->store->count_source_items_by_statuses( $session->get_id(), $terminal_statuses );
		$failed    = $this->store->count_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_FAILED ) );
		$cursor    = empty( $traversal['complete'] ) ? 'source-discovery:queued' : 'source-discovery:complete';

		return $session
			->with_progress( new ImportProgress( $total, $completed, $failed ) )
			->with_checkpoint( new ImportCheckpoint( $cursor, $completed ) );
	}

	/**
	 * Marks sessions done when their bounded completion criteria are met.
	 *
	 * @param ImportSession       $session       Session.
	 * @param array<string,mixed> $traversal     Traversal summary.
	 * @param array<string,mixed> $media_imports Media import summary.
	 * @param array<string,mixed> $posts         Post persistence summary.
	 * @param array<string,mixed> $epub_links    EPUB internal link summary.
	 * @param array<string,mixed> $markdown_links Markdown internal link summary.
	 * @param array<string,mixed> $postmeta      Postmeta persistence summary.
	 * @param array<string,mixed> $att_meta      Attachment metadata summary.
	 * @param array<string,mixed> $parents       Attachment parent summary.
	 * @param array<string,mixed> $comments      Comment persistence summary.
	 * @param array<string,mixed> $mappings      Relationship mapping summary.
	 * @param array<string,mixed> $menus         Navigation menu summary.
	 * @return ImportSession
	 */
	private function with_completion_status( ImportSession $session, array $traversal, array $media_imports, array $posts, array $epub_links, array $markdown_links, array $postmeta, array $att_meta, array $parents, array $comments, array $mappings, array $menus ) {
		if ( empty( $traversal['complete'] ) ) {
			return $session;
		}

		if ( $this->has_pending_importer_state_work( $session ) ) {
			return $session;
		}

		if ( $session->is_dry_run() ) {
			return $session->mark_done();
		}

		if ( $this->has_failed_or_blocked_content_work( $media_imports, $posts, $epub_links, $markdown_links, $postmeta, $att_meta, $parents, $comments, $mappings, $menus ) ) {
			return $session;
		}

		$prepared_documents = $this->store->count_prepared_documents( $session->get_id() );
		$prepared_records   = $this->store->count_idempotency_records_by_resource_type( $session->get_id(), 'prepared_document' );
		$persisted_posts    = $this->store->count_idempotency_records_by_resource_type( $session->get_id(), 'post' );

		if ( $prepared_records < $prepared_documents || $persisted_posts < $prepared_documents ) {
			return $session;
		}

		if ( $this->has_unresolved_epub_internal_links( $session ) ) {
			return $session;
		}

		if ( $this->has_unresolved_markdown_internal_links( $session ) ) {
			return $session;
		}

		if ( $this->has_pending_wxr_nav_menu_work( $session ) ) {
			return $session;
		}

		if ( $this->has_pending_wxr_attachment_work( $session ) ) {
			return $session;
		}

		if ( 0 !== $this->store->count_media_references_by_statuses( $session->get_id(), array( ImportMediaReference::STATUS_QUEUED, ImportMediaReference::STATUS_FAILED ) ) ) {
			return $session;
		}

		return $session->mark_done();
	}

	/**
	 * Returns whether importer-state queues or decisions still need work.
	 *
	 * @param ImportSession $session Session.
	 * @return bool
	 */
	private function has_pending_importer_state_work( ImportSession $session ) {
		if ( ! empty( $this->store->list_pending_decisions( $session->get_id() ) ) ) {
			return true;
		}

		if ( 0 !== $this->store->count_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_FAILED ) ) ) {
			return true;
		}

		return 0 !== $this->store->count_source_items_by_statuses(
			$session->get_id(),
			array(
				ImportSourceItem::STATUS_QUEUED,
				ImportSourceItem::STATUS_PROCESSING,
				ImportSourceItem::STATUS_DISCOVERED,
			)
		);
	}

	/**
	 * Returns whether downstream WordPress content work should keep the session running.
	 *
	 * @param array<string,mixed> $media_imports Media import summary.
	 * @param array<string,mixed> $posts         Post persistence summary.
	 * @param array<string,mixed> $epub_links    EPUB internal link summary.
	 * @param array<string,mixed> $markdown_links Markdown internal link summary.
	 * @param array<string,mixed> $postmeta      Postmeta persistence summary.
	 * @param array<string,mixed> $att_meta      Attachment metadata summary.
	 * @param array<string,mixed> $parents       Attachment parent summary.
	 * @param array<string,mixed> $comments      Comment persistence summary.
	 * @param array<string,mixed> $mappings      Relationship mapping summary.
	 * @param array<string,mixed> $menus         Navigation menu summary.
	 * @return bool
	 */
	private function has_failed_or_blocked_content_work( array $media_imports, array $posts, array $epub_links, array $markdown_links, array $postmeta, array $att_meta, array $parents, array $comments, array $mappings, array $menus ) {
		if ( ! empty( $media_imports['blocked'] ) ) {
			return true;
		}

		foreach ( array( $media_imports, $posts, $epub_links, $markdown_links, $postmeta, $att_meta, $parents, $comments, $mappings, $menus ) as $summary ) {
			foreach ( array( 'failed', 'deferred' ) as $key ) {
				if ( ! empty( $summary[ $key ] ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Returns whether prepared EPUB spine documents still contain unresolved internal links.
	 *
	 * @param ImportSession $session Session.
	 * @return bool
	 */
	private function has_unresolved_epub_internal_links( ImportSession $session ) {
		$limit                 = 500;
		$after_source_item_key = null;

		do {
			$documents      = $this->store->list_prepared_documents_after_source_item_key(
				$session->get_id(),
				$after_source_item_key,
				$limit
			);
			$document_count = count( $documents );

			foreach ( $documents as $document ) {
				$after_source_item_key = $document->get_source_item_key();

				if ( 'epub' !== $document->get_format() ) {
					continue;
				}

				$metadata = $document->get_metadata();
				if ( empty( $metadata['epub_internal_links'] ) || ! is_array( $metadata['epub_internal_links'] ) ) {
					continue;
				}

				foreach ( $metadata['epub_internal_links'] as $link ) {
					if ( is_array( $link ) && empty( $link['resolved_href'] ) && ! empty( $link['rewritten_href'] ) ) {
						return true;
					}
				}
			}
		} while ( $document_count === $limit );

		return false;
	}

	/**
	 * Returns whether prepared Markdown documents still contain local document links.
	 *
	 * @param ImportSession $session Session.
	 * @return bool
	 */
	private function has_unresolved_markdown_internal_links( ImportSession $session ) {
		return ( new ImportMarkdownInternalLinkResolver( $this->store, $this->post_gateway ) )->has_unresolved_links( $session );
	}

	/**
	 * Returns whether staged remote comments still need a later persistence pass.
	 *
	 * @param ImportSession $session Session.
	 * @return bool
	 */
	private function has_pending_remote_comments( ImportSession $session ) {
		$limit                 = 500;
		$after_source_item_key = null;

		do {
			$documents      = $this->store->list_prepared_documents_after_source_item_key(
				$session->get_id(),
				$after_source_item_key,
				$limit
			);
			$document_count = count( $documents );

			foreach ( $documents as $document ) {
				$source_item_key       = $document->get_source_item_key();
				$after_source_item_key = $source_item_key;
				$metadata              = $document->get_metadata();

				if ( empty( $metadata['remote_comments'] ) || ! is_array( $metadata['remote_comments'] ) ) {
					continue;
				}

				$local_comments = isset( $metadata['local_comments'] ) && is_array( $metadata['local_comments'] ) ? $metadata['local_comments'] : array();

				foreach ( $metadata['remote_comments'] as $comment ) {
					if ( ! is_array( $comment ) ) {
						continue;
					}

					$remote_comment_id = isset( $comment['remote_comment_id'] ) ? (int) $comment['remote_comment_id'] : ( isset( $comment['id'] ) ? (int) $comment['id'] : 0 );

					if ( $remote_comment_id < 1 ) {
						continue;
					}

					if ( isset( $local_comments[ (string) $remote_comment_id ] ) ) {
						continue;
					}

					if ( null === $this->store->find_idempotency_record( $session->get_id(), 'comment:' . $source_item_key . ':' . $remote_comment_id ) ) {
						return true;
					}
				}
			}
		} while ( $document_count === $limit );

		return false;
	}

	/**
	 * Returns whether staged WXR navigation menu metadata still needs persistence.
	 *
	 * @param ImportSession $session Session.
	 * @return bool
	 */
	private function has_pending_wxr_nav_menu_work( ImportSession $session ) {
		$limit          = 500;
		$after_item_key = null;

		do {
			$source_items      = $this->store->list_source_items_by_statuses_after_item_key(
				$session->get_id(),
				array( ImportSourceItem::STATUS_IMPORTED ),
				$after_item_key,
				$limit
			);
			$source_item_count = count( $source_items );

			foreach ( $source_items as $source_item ) {
				$after_item_key = $source_item->get_key();
				$metadata       = $source_item->get_metadata();

				if ( empty( $metadata['wxr_nav_menu_items_by_id'] ) || ! is_array( $metadata['wxr_nav_menu_items_by_id'] ) ) {
					continue;
				}

				$menu_slugs = array();

				foreach ( $metadata['wxr_nav_menu_items_by_id'] as $item ) {
					if ( ! is_array( $item ) || ! isset( $item['id'] ) || '' === trim( (string) $item['id'] ) ) {
						continue;
					}

					$menu_slug = isset( $item['menu_slug'] ) && '' !== trim( (string) $item['menu_slug'] ) ? trim( (string) $item['menu_slug'] ) : 'imported-menu';

					$menu_slugs[ $menu_slug ] = true;

					if ( null === $this->store->find_idempotency_record( $session->get_id(), 'nav-menu-item:' . $source_item->get_key() . ':' . (string) $item['id'] ) ) {
						return true;
					}
				}

				foreach ( array_keys( $menu_slugs ) as $menu_slug ) {
					if ( null === $this->store->find_idempotency_record( $session->get_id(), 'nav-menu-location:' . $source_item->get_key() . ':' . $menu_slug ) ) {
						return true;
					}
				}
			}
		} while ( $source_item_count === $limit );

		return false;
	}

	/**
	 * Returns whether imported WXR attachment metadata or parents still need persistence.
	 *
	 * @param ImportSession $session Session.
	 * @return bool
	 */
	private function has_pending_wxr_attachment_work( ImportSession $session ) {
		$limit               = 500;
		$after_reference_key = null;

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
				$metadata            = $reference->get_metadata();

				if ( ! isset( $metadata['source'], $metadata['wxr_attachment_id'] ) || 'wxr' !== (string) $metadata['source'] ) {
					continue;
				}

				if (
					! empty( ImportWxrAttachment::metadata_from_reference( $reference ) )
					&& null === $this->store->find_idempotency_record( $session->get_id(), 'attachment-metadata:' . $reference->get_key() )
				) {
					return true;
				}

				if (
					isset( $metadata['wxr_post_parent'] )
					&& (int) $metadata['wxr_post_parent'] > 0
					&& null === $this->store->find_idempotency_record( $session->get_id(), 'attachment-parent:' . $reference->get_key() )
				) {
					return true;
				}
			}
		} while ( $reference_count === $limit );

		return false;
	}

	/**
	 * Combines local and remote source discovery summaries for progress.
	 *
	 * @param array<string,mixed> $local  Local traversal summary.
	 * @param array<string,mixed> $github GitHub traversal summary.
	 * @param array<string,mixed> $remote Remote URL traversal summary.
	 * @return array{discovered:int,queued:int,failed:int,complete:bool,message:string}
	 */
	private function combine_traversal_summaries( array $local, array $github, array $remote ) {
		$has_local_work  = 0 < $local['discovered'] || 0 < $local['queued'] || 0 < $local['failed'] || ! empty( $local['complete'] );
		$has_github_work = 0 < $github['discovered'] || 0 < $github['queued'] || 0 < $github['failed'] || ! empty( $github['complete'] );
		$has_remote_work = 0 < $remote['discovered'] || 0 < $remote['queued'] || 0 < $remote['failed'] || ! empty( $remote['complete'] );
		$message         = $has_remote_work ? $remote['message'] : ( $has_github_work ? $github['message'] : $local['message'] );

		if ( $has_local_work && $has_github_work ) {
			$message = $local['message'] . ' ' . $github['message'];
		}

		if ( ( $has_local_work || $has_github_work ) && $has_remote_work ) {
			$message .= ' ' . $remote['message'];
		}

		return array(
			'discovered' => $local['discovered'] + $github['discovered'] + $remote['discovered'],
			'queued'     => $local['queued'] + $github['queued'] + $remote['queued'],
			'failed'     => $local['failed'] + $github['failed'] + $remote['failed'],
			'complete'   => ! empty( $local['complete'] ) || ! empty( $github['complete'] ) || ! empty( $remote['complete'] ),
			'message'    => $message,
		);
	}

	/**
	 * Applies bounded hidden failure simulations for adversarial tests.
	 *
	 * @param ImportSession $session Current locked session.
	 * @return bool Whether the runner should continue processing this session.
	 * @throws RuntimeException When crash simulation is enabled.
	 */
	private function apply_failure_simulations( ImportSession $session ) {
		$memory_pressure_bytes = $this->controls->get_memory_pressure_bytes();

		if ( 0 < $memory_pressure_bytes ) {
			$pressure = str_repeat( 'x', $memory_pressure_bytes );
			$this->store->record_event(
				$session->get_id(),
				new ImportProgressEvent(
					ImportProgressEvent::LEVEL_WARNING,
					'runner.simulated_memory_pressure',
					'Runner allocated bounded memory pressure for recovery testing.',
					array(
						'owner' => $this->owner,
						'bytes' => strlen( $pressure ),
					)
				)
			);
			unset( $pressure );
		}

		if ( $this->controls->should_simulate_timeout() ) {
			$this->store->record_event(
				$session->get_id(),
				new ImportProgressEvent(
					ImportProgressEvent::LEVEL_WARNING,
					'runner.simulated_timeout',
					'Runner stopped early to simulate an execution time budget.',
					array( 'owner' => $this->owner )
				)
			);

			if ( ! $this->controls->should_simulate_crash() ) {
				return false;
			}
		}

		if ( $this->controls->should_simulate_crash() ) {
			$this->store->record_event(
				$session->get_id(),
				new ImportProgressEvent(
					ImportProgressEvent::LEVEL_ERROR,
					'runner.simulated_crash',
					'Runner is throwing a controlled crash for recovery testing.',
					array( 'owner' => $this->owner )
				)
			);

			throw new RuntimeException( 'Simulated importer crash after acquiring the session lock.' );
		}

		if ( $this->controls->should_simulate_fatal_exit() ) {
			$this->store->record_event(
				$session->get_id(),
				new ImportProgressEvent(
					ImportProgressEvent::LEVEL_ERROR,
					'runner.simulated_fatal_exit',
					'Runner is terminating PHP after durable lock and event writes for recovery testing.',
					array( 'owner' => $this->owner )
				)
			);

			exit( 117 );
		}

		return true;
	}

	/**
	 * Builds a post summary when persistence waits on a user decision.
	 *
	 * @param array<string,mixed> $urls URL or media blocking summary.
	 * @return array{created:int,updated:int,skipped:int,failed:int,message:string}
	 */
	private function blocked_post_summary( array $urls ) {
		return array(
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
			'failed'  => 0,
			'message' => $urls['message'],
		);
	}

	/**
	 * Combines two post persistence summaries.
	 *
	 * @param array<string,mixed> $first  First summary.
	 * @param array<string,mixed> $second Second summary.
	 * @return array{created:int,updated:int,skipped:int,failed:int,message:string}
	 */
	private function combine_post_summaries( array $first, array $second ) {
		return array(
			'created' => $first['created'] + $second['created'],
			'updated' => $first['updated'] + $second['updated'],
			'skipped' => $first['skipped'] + $second['skipped'],
			'failed'  => $first['failed'] + $second['failed'],
			'message' => $second['message'],
		);
	}

	/**
	 * Records that a dry-run tick skipped WordPress content mutations.
	 *
	 * @param ImportSession       $session   Session.
	 * @param array<string,mixed> $documents Document processing summary.
	 * @param array<string,mixed> $media     Media reference detection summary.
	 * @return void
	 */
	private function record_dry_run_write_skip_event( ImportSession $session, array $documents, array $media ) {
		$this->store->record_event(
			$session->get_id(),
			new ImportProgressEvent(
				ImportProgressEvent::LEVEL_INFO,
				'session.dry_run_write_skipped',
				'Dry run skipped WordPress content mutations.',
				array(
					'owner'              => $this->owner,
					'documents_imported' => isset( $documents['imported'] ) ? (int) $documents['imported'] : 0,
					'documents_skipped'  => isset( $documents['skipped'] ) ? (int) $documents['skipped'] : 0,
					'media_queued'       => isset( $media['queued'] ) ? (int) $media['queued'] : 0,
					'media_skipped'      => isset( $media['skipped'] ) ? (int) $media['skipped'] : 0,
				)
			)
		);
	}

	/**
	 * Builds a no-op media import summary for dry-run sessions.
	 *
	 * @return array{imported:int,rewritten:int,skipped:int,failed:int,blocked:bool,message:string}
	 */
	private function dry_run_media_import_summary() {
		return array(
			'imported'  => 0,
			'rewritten' => 0,
			'skipped'   => 0,
			'failed'    => 0,
			'blocked'   => true,
			'message'   => 'Dry-run session skipped WordPress media attachment writes.',
		);
	}

	/**
	 * Builds a no-op post persistence summary for dry-run sessions.
	 *
	 * @return array{created:int,updated:int,skipped:int,failed:int,message:string}
	 */
	private function dry_run_post_summary() {
		return array(
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
			'failed'  => 0,
			'message' => 'Dry-run session skipped WordPress post writes.',
		);
	}

	/**
	 * Builds a no-op EPUB link summary for dry-run sessions.
	 *
	 * @return array{resolved:int,deferred:int,skipped:int,failed:int,message:string}
	 */
	private function dry_run_epub_link_summary() {
		return array(
			'resolved' => 0,
			'deferred' => 0,
			'skipped'  => 0,
			'failed'   => 0,
			'message'  => 'Dry-run session skipped imported post link rewrites.',
		);
	}

	/**
	 * Builds a no-op Markdown link summary for dry-run sessions.
	 *
	 * @return array{resolved:int,deferred:int,skipped:int,failed:int,message:string}
	 */
	private function dry_run_markdown_link_summary() {
		return array(
			'resolved' => 0,
			'deferred' => 0,
			'skipped'  => 0,
			'failed'   => 0,
			'message'  => 'Dry-run session skipped Markdown document link rewrites.',
		);
	}

	/**
	 * Builds a no-op postmeta summary for dry-run sessions.
	 *
	 * @return array{applied:int,skipped:int,deferred:int,failed:int,message:string}
	 */
	private function dry_run_postmeta_summary() {
		return array(
			'applied'  => 0,
			'skipped'  => 0,
			'deferred' => 0,
			'failed'   => 0,
			'message'  => 'Dry-run session skipped WordPress post meta writes.',
		);
	}

	/**
	 * Builds a no-op attachment metadata summary for dry-run sessions.
	 *
	 * @return array{applied:int,skipped:int,deferred:int,failed:int,message:string}
	 */
	private function dry_run_attachment_metadata_summary() {
		return array(
			'applied'  => 0,
			'skipped'  => 0,
			'deferred' => 0,
			'failed'   => 0,
			'message'  => 'Dry-run session skipped WordPress attachment metadata writes.',
		);
	}

	/**
	 * Builds a no-op attachment parent summary for dry-run sessions.
	 *
	 * @return array{applied:int,skipped:int,deferred:int,failed:int,message:string}
	 */
	private function dry_run_attachment_parent_summary() {
		return array(
			'applied'  => 0,
			'skipped'  => 0,
			'deferred' => 0,
			'failed'   => 0,
			'message'  => 'Dry-run session skipped WordPress attachment parent writes.',
		);
	}

	/**
	 * Builds a no-op comment summary for dry-run sessions.
	 *
	 * @return array{created:int,updated:int,skipped:int,deferred:int,failed:int,message:string}
	 */
	private function dry_run_comment_summary() {
		return array(
			'created'  => 0,
			'updated'  => 0,
			'skipped'  => 0,
			'deferred' => 0,
			'failed'   => 0,
			'message'  => 'Dry-run session skipped WordPress comment writes.',
		);
	}

	/**
	 * Builds a no-op relationship mapping summary for dry-run sessions.
	 *
	 * @return array{applied:int,skipped:int,failed:int,message:string}
	 */
	private function dry_run_mapping_summary() {
		return array(
			'applied' => 0,
			'skipped' => 0,
			'failed'  => 0,
			'message' => 'Dry-run session skipped WordPress relationship mapping writes.',
		);
	}

	/**
	 * Builds a no-op navigation menu summary for dry-run sessions.
	 *
	 * @return array{applied:int,skipped:int,deferred:int,failed:int,message:string}
	 */
	private function dry_run_nav_menu_summary() {
		return array(
			'applied'  => 0,
			'skipped'  => 0,
			'deferred' => 0,
			'failed'   => 0,
			'message'  => 'Dry-run session skipped WordPress navigation menu writes.',
		);
	}

	/**
	 * Builds a no-op EPUB link summary while URL/media work is blocked.
	 *
	 * @param array<string,mixed> $blocked Blocking summary.
	 * @return array{resolved:int,deferred:int,skipped:int,failed:int,message:string}
	 */
	private function blocked_epub_link_summary( array $blocked ) {
		return array(
			'resolved' => 0,
			'deferred' => 0,
			'skipped'  => 0,
			'failed'   => 0,
			'message'  => $blocked['message'],
		);
	}

	/**
	 * Builds a no-op Markdown link summary while URL/media work is blocked.
	 *
	 * @param array<string,mixed> $blocked Blocking summary.
	 * @return array{resolved:int,deferred:int,skipped:int,failed:int,message:string}
	 */
	private function blocked_markdown_link_summary( array $blocked ) {
		return array(
			'resolved' => 0,
			'deferred' => 0,
			'skipped'  => 0,
			'failed'   => 0,
			'message'  => $blocked['message'],
		);
	}

	/**
	 * Builds a comment summary when persistence waits on a user decision or media import.
	 *
	 * @param array<string,mixed> $blocked Blocking summary.
	 * @return array{created:int,updated:int,skipped:int,deferred:int,failed:int,message:string}
	 */
	private function blocked_comment_summary( array $blocked ) {
		return array(
			'created'  => 0,
			'updated'  => 0,
			'skipped'  => 0,
			'deferred' => 0,
			'failed'   => 0,
			'message'  => $blocked['message'],
		);
	}

	/**
	 * Builds a navigation menu summary when persistence waits on a user decision or media import.
	 *
	 * @param array<string,mixed> $blocked Blocking summary.
	 * @return array{applied:int,skipped:int,deferred:int,failed:int,message:string}
	 */
	private function blocked_nav_menu_summary( array $blocked ) {
		return array(
			'applied'  => 0,
			'skipped'  => 0,
			'deferred' => 0,
			'failed'   => 0,
			'message'  => $blocked['message'],
		);
	}

	/**
	 * Builds a postmeta summary when persistence waits on a user decision or media import.
	 *
	 * @param array<string,mixed> $blocked Blocking summary.
	 * @return array{applied:int,skipped:int,deferred:int,failed:int,message:string}
	 */
	private function blocked_postmeta_summary( array $blocked ) {
		return array(
			'applied'  => 0,
			'skipped'  => 0,
			'deferred' => 0,
			'failed'   => 0,
			'message'  => $blocked['message'],
		);
	}

	/**
	 * Builds an attachment parent summary when persistence waits on a user decision or media import.
	 *
	 * @param array<string,mixed> $blocked Blocking summary.
	 * @return array{applied:int,skipped:int,deferred:int,failed:int,message:string}
	 */
	private function blocked_attachment_parent_summary( array $blocked ) {
		return array(
			'applied'  => 0,
			'skipped'  => 0,
			'deferred' => 0,
			'failed'   => 0,
			'message'  => $blocked['message'],
		);
	}

	/**
	 * Builds an attachment metadata summary when persistence waits on a user decision or media import.
	 *
	 * @param array<string,mixed> $blocked Blocking summary.
	 * @return array{applied:int,skipped:int,deferred:int,failed:int,message:string}
	 */
	private function blocked_attachment_metadata_summary( array $blocked ) {
		return array(
			'applied'  => 0,
			'skipped'  => 0,
			'deferred' => 0,
			'failed'   => 0,
			'message'  => $blocked['message'],
		);
	}

	/**
	 * Builds a no-op rewrite summary while URL confirmation is pending.
	 *
	 * @return array{rewritten:int,skipped:int,confirmed_domains:array<int,string>,message:string}
	 */
	private function blocked_rewrite_summary() {
		return array(
			'rewritten'         => 0,
			'skipped'           => 0,
			'confirmed_domains' => array(),
			'message'           => 'URL rewriting is waiting for first-party domain confirmation.',
		);
	}

	/**
	 * Builds a no-op media summary while URL confirmation is pending.
	 *
	 * @return array{queued:int,skipped:int,message:string}
	 */
	private function blocked_media_summary() {
		return array(
			'queued'  => 0,
			'skipped' => 0,
			'message' => 'Media reference queueing is waiting for first-party domain confirmation.',
		);
	}

	/**
	 * Builds a no-op media import summary while URL confirmation is pending.
	 *
	 * @return array{imported:int,rewritten:int,skipped:int,failed:int,blocked:bool,message:string}
	 */
	private function blocked_media_import_summary() {
		return array(
			'imported'  => 0,
			'rewritten' => 0,
			'skipped'   => 0,
			'failed'    => 0,
			'blocked'   => true,
			'message'   => 'Media attachment import is waiting for first-party domain confirmation.',
		);
	}

	/**
	 * Builds a readable owner name for locks and events.
	 *
	 * @return string
	 */
	private function default_owner() {
		$host = function_exists( 'php_uname' ) ? php_uname( 'n' ) : 'unknown-host';

		return 'import-runner:' . $host . ':' . getmypid();
	}
}
