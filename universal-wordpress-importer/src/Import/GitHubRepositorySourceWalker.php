<?php
/**
 * GitHub repository source walker.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Importer-managed cache files are written outside the uploads API by design.

use RuntimeException;
use Throwable;

/**
 * Seeds GitHub repository URLs into the durable source queue through sparse Git or GitHub tree traversal.
 */
final class GitHubRepositorySourceWalker {
	const TREE_BLOB_LIMIT         = 100;
	const CONTENTS_API_FILE_LIMIT = 1000;

	/**
	 * Durable store.
	 *
	 * @var WordPressImportSessionStore
	 */
	private $store;

	/**
	 * Remote JSON fetcher for GitHub tree/blob APIs.
	 *
	 * @var ImportRemoteContentFetcherInterface|null
	 */
	private $content_fetcher;

	/**
	 * Git repository fetcher for php-toolkit sparse pulls.
	 *
	 * @var GitRepositoryFetcherInterface|null
	 */
	private $git_fetcher;

	/**
	 * Cache root.
	 *
	 * @var ImportCacheDirectory
	 */
	private $cache_directory;

	/**
	 * Hidden failure simulation controls for adversarial tests.
	 *
	 * @var ImportRunnerControls
	 */
	private $controls;

	/**
	 * Constructor.
	 *
	 * @param WordPressImportSessionStore              $store      Durable store.
	 * @param ImportRemoteArchiveFetcherInterface|null $fetcher  Deprecated archive fetcher argument, ignored for GitHub imports.
	 * @param string|ImportCacheDirectory|null         $cache_root Optional cache root.
	 * @param ImportRemoteContentFetcherInterface|null $content_fetcher Optional GitHub tree/blob fetcher.
	 * @param ImportRunnerControls|null                $controls Optional hidden test controls.
	 * @param GitRepositoryFetcherInterface|null       $git_fetcher Optional Git sparse checkout fetcher.
	 */
	public function __construct( WordPressImportSessionStore $store, ImportRemoteArchiveFetcherInterface $fetcher = null, $cache_root = null, ImportRemoteContentFetcherInterface $content_fetcher = null, ImportRunnerControls $controls = null, GitRepositoryFetcherInterface $git_fetcher = null ) {
		$this->store           = $store;
		$this->content_fetcher = $content_fetcher;
		$this->git_fetcher     = $git_fetcher;
		$this->cache_directory = $cache_root instanceof ImportCacheDirectory ? $cache_root : ( null === $cache_root ? ImportCacheDirectory::from_environment() : new ImportCacheDirectory( $cache_root ) );
		$this->controls        = null === $controls ? ImportRunnerControls::none() : $controls;
	}

	/**
	 * Advances GitHub repository discovery for a session.
	 *
	 * @param ImportSession $session Session.
	 * @return array{discovered:int,queued:int,failed:int,complete:bool,message:string}
	 * @throws RuntimeException When source item persistence fails unexpectedly.
	 */
	public function advance( ImportSession $session ) {
		$summary = array(
			'discovered' => 0,
			'queued'     => 0,
			'failed'     => 0,
			'complete'   => false,
			'message'    => 'Source is not a GitHub repository URL.',
		);
		$repo    = $this->parse_repository_url( $session->get_source() );

		if ( null === $repo ) {
			return $summary;
		}

		$repositories = $this->candidate_repositories( $repo );
		$deferred     = $this->deferred_rate_limit_summary( $session, $repositories );
		if ( null !== $deferred ) {
			return $deferred;
		}

		$git_summary = $this->queue_git_items( $session, $repositories );
		if ( null !== $git_summary ) {
			return $git_summary;
		}

		$tree_summary = $this->queue_tree_items( $session, $repositories );
		if ( null !== $tree_summary ) {
			return $tree_summary;
		}

		$message = 'GitHub repository traversal could not queue files through sparse Git or the GitHub tree API. Zipball fallback is disabled for GitHub imports.';
		$this->store->save_source_item(
			ImportSourceItem::queued(
				$session->get_id(),
				$this->item_key( $repo ),
				null,
				$repo['source_url'],
				$repo['source_url'],
				ImportSourceItem::TYPE_DIRECTORY,
				array(
					'github_owner'         => $repo['owner'],
					'github_repository'    => $repo['name'],
					'github_ref'           => $repo['ref'],
					'github_requested_ref' => isset( $repo['requested_ref'] ) ? $repo['requested_ref'] : $repo['ref'],
					'github_source_url'    => $repo['source_url'],
					'github_source_path'   => $repo['source_path'],
					'github_zipball'       => false,
					'error'                => $message,
				)
			)->with_status( ImportSourceItem::STATUS_FAILED )
		);
		$this->record_event( $session, 'github.traversal_failed', $message, $repo, array(), ImportProgressEvent::LEVEL_ERROR );
		$summary['failed']   = 1;
		$summary['complete'] = true;
		$summary['message']  = $message;

		return $summary;
	}

	/**
	 * Queues GitHub files discovered through php-toolkit Git sparse pulls.
	 *
	 * @param ImportSession                                                                                                    $session      Session.
	 * @param array<int,array{owner:string,name:string,ref:string,source_path:string,source_url:string,requested_ref?:string}> $repositories Candidate repositories.
	 * @return array{discovered:int,queued:int,failed:int,complete:bool,message:string}|null
	 * @throws RuntimeException When source item persistence fails unexpectedly.
	 */
	private function queue_git_items( ImportSession $session, array $repositories ) {
		if ( null === $this->git_fetcher ) {
			return null;
		}

		foreach ( $repositories as $candidate ) {
			if ( ! $this->can_queue_git_items( $candidate ) ) {
				continue;
			}

			$state = $this->git_state_item( $session, $candidate );
			if ( $this->is_git_state_complete( $state ) ) {
				return array(
					'discovered' => 0,
					'queued'     => 0,
					'failed'     => 0,
					'complete'   => true,
					'message'    => 'GitHub repository files are already present in the source queue.',
				);
			}

			if ( $this->is_git_state_unavailable( $state ) ) {
				continue;
			}

			try {
				$files = $this->git_fetcher->fetch( $session, $candidate, $this->cache_directory );
			} catch ( Throwable $exception ) {
				$metadata                      = $state->get_metadata();
				$metadata['github_git_status'] = 'unavailable';
				$metadata['error']             = $exception->getMessage();
				$this->store->save_source_item( $state->with_status( ImportSourceItem::STATUS_SKIPPED )->with_replaced_metadata( $metadata ) );
				$this->record_event(
					$session,
					'github.git_unavailable',
					'php-toolkit Git traversal could not fetch this repository candidate; traversal will use the next available GitHub path.',
					$candidate,
					array(
						'error' => $exception->getMessage(),
					),
					ImportProgressEvent::LEVEL_WARNING
				);
				continue;
			}

			$queued = 0;
			foreach ( $files as $file ) {
				if ( ! is_array( $file ) ) {
					continue;
				}

				$item = $this->build_git_file_item( $session, $candidate, $file );
				if ( null === $item ) {
					continue;
				}

				$this->store->save_source_item( $item );
				++$queued;
			}

			if ( 0 === $queued ) {
				$this->mark_git_state_complete( $session, $candidate, $state, count( $files ), $queued );
				return array(
					'discovered' => 0,
					'queued'     => 0,
					'failed'     => 0,
					'complete'   => true,
					'message'    => 'GitHub repository files are already present in the source queue.',
				);
			}

			$this->mark_git_state_complete( $session, $candidate, $state, count( $files ), $queued );
			$this->mark_tree_state_complete( $session, $repositories );
			$this->record_event(
				$session,
				'github.git_queued',
				'GitHub repository files were queued through php-toolkit Git sparse checkout.',
				$candidate,
				array(
					'files' => $queued,
				)
			);

			return array(
				'discovered' => $queued,
				'queued'     => $queued,
				'failed'     => 0,
				'complete'   => true,
				'message'    => 'GitHub repository files were queued through php-toolkit Git sparse checkout.',
			);
		}

		return null;
	}

	/**
	 * Returns whether a candidate can use php-toolkit Git traversal.
	 *
	 * @param array{owner:string,name:string,ref:string,source_path:string,source_url:string,requested_ref?:string} $repo Repository data.
	 * @return bool
	 */
	private function can_queue_git_items( array $repo ) {
		$ref = isset( $repo['ref'] ) ? trim( (string) $repo['ref'] ) : '';

		if ( '' === $ref || 'HEAD' === strtoupper( $ref ) ) {
			return false;
		}

		return ! preg_match( '/^[0-9a-f]{40}$/i', $ref );
	}

	/**
	 * Builds a discovered source item for a file fetched through sparse Git.
	 *
	 * @param ImportSession                                                                                         $session Session.
	 * @param array{owner:string,name:string,ref:string,source_path:string,source_url:string,requested_ref?:string} $repo Repository data.
	 * @param array<string,mixed>                                                                                   $file File descriptor.
	 * @return ImportSourceItem|null Source item to persist, or null when it already exists.
	 */
	private function build_git_file_item( ImportSession $session, array $repo, array $file ) {
		$repository_path = isset( $file['repository_path'] ) ? $this->normalize_source_path( (string) $file['repository_path'] ) : '';
		$relative_path   = isset( $file['relative_path'] ) ? trim( str_replace( '\\', '/', (string) $file['relative_path'] ), '/' ) : '';
		$local_path      = isset( $file['local_path'] ) ? (string) $file['local_path'] : '';

		if ( '' === $repository_path || '' === $relative_path || '' === $local_path ) {
			return null;
		}

		$item_key = $this->git_item_key( $repo, $repository_path );
		if ( null !== $this->store->find_source_item( $session->get_id(), $item_key ) ) {
			return null;
		}

		$metadata = array(
			'basename'             => basename( $relative_path ),
			'bytes'                => isset( $file['bytes'] ) ? max( 0, (int) $file['bytes'] ) : 0,
			'extension'            => strtolower( pathinfo( $relative_path, PATHINFO_EXTENSION ) ),
			'github_owner'         => $repo['owner'],
			'github_repository'    => $repo['name'],
			'github_ref'           => $repo['ref'],
			'github_requested_ref' => isset( $repo['requested_ref'] ) ? $repo['requested_ref'] : $repo['ref'],
			'github_source_url'    => $repo['source_url'],
			'github_source_path'   => $repo['source_path'],
			'github_tree_path'     => $repository_path,
			'github_git_fetch'     => true,
		);

		if ( isset( $file['metadata'] ) && is_array( $file['metadata'] ) ) {
			$metadata += $file['metadata'];
		}

		return ImportSourceItem::queued(
			$session->get_id(),
			$item_key,
			null,
			$local_path,
			$relative_path,
			ImportSourceItem::TYPE_FILE,
			$metadata
		)->with_status( ImportSourceItem::STATUS_DISCOVERED );
	}

	/**
	 * Returns whether a Git state item is already complete.
	 *
	 * @param ImportSourceItem $item Git state item.
	 * @return bool
	 */
	private function is_git_state_complete( ImportSourceItem $item ) {
		$metadata = $item->get_metadata();

		return ImportSourceItem::STATUS_SKIPPED === $item->get_status() && isset( $metadata['github_git_status'] ) && 'complete' === $metadata['github_git_status'];
	}

	/**
	 * Returns whether a Git state item has already failed over to another path.
	 *
	 * @param ImportSourceItem $item Git state item.
	 * @return bool
	 */
	private function is_git_state_unavailable( ImportSourceItem $item ) {
		$metadata = $item->get_metadata();

		return ImportSourceItem::STATUS_SKIPPED === $item->get_status() && isset( $metadata['github_git_status'] ) && 'unavailable' === $metadata['github_git_status'];
	}

	/**
	 * Marks Git traversal state complete.
	 *
	 * @param ImportSession                                                                                         $session     Session.
	 * @param array{owner:string,name:string,ref:string,source_path:string,source_url:string,requested_ref?:string} $repo        Repository data.
	 * @param ImportSourceItem                                                                                      $state       State item.
	 * @param int                                                                                                   $total_files Total files discovered by Git.
	 * @param int                                                                                                   $queued      Files queued on this tick.
	 * @return void
	 */
	private function mark_git_state_complete( ImportSession $session, array $repo, ImportSourceItem $state, $total_files, $queued ) {
		$metadata = array_merge(
			$state->get_metadata(),
			array(
				'github_git_status'       => 'complete',
				'github_git_total_files'  => max( 0, (int) $total_files ),
				'github_git_queued_files' => max( 0, (int) $queued ),
			)
		);

		$this->store->save_source_item( $state->with_status( ImportSourceItem::STATUS_SKIPPED )->with_replaced_metadata( $metadata ) );
	}

	/**
	 * Builds or loads a durable Git traversal state item.
	 *
	 * @param ImportSession                                                                                         $session Session.
	 * @param array{owner:string,name:string,ref:string,source_path:string,source_url:string,requested_ref?:string} $repo Repository data.
	 * @return ImportSourceItem
	 */
	private function git_state_item( ImportSession $session, array $repo ) {
		$existing = $this->store->find_source_item( $session->get_id(), $this->git_state_key( $repo ) );
		if ( null !== $existing ) {
			return $existing;
		}

		return ImportSourceItem::queued(
			$session->get_id(),
			$this->git_state_key( $repo ),
			null,
			$repo['source_url'],
			$repo['source_url'],
			ImportSourceItem::TYPE_DIRECTORY,
			array(
				'github_owner'         => $repo['owner'],
				'github_repository'    => $repo['name'],
				'github_ref'           => $repo['ref'],
				'github_requested_ref' => isset( $repo['requested_ref'] ) ? $repo['requested_ref'] : $repo['ref'],
				'github_source_url'    => $repo['source_url'],
				'github_source_path'   => $repo['source_path'],
				'github_git_status'    => 'pending',
			)
		);
	}

	/**
	 * Queues GitHub tree/blob API results as discovered source files.
	 *
	 * @param ImportSession                                                                                                    $session      Session.
	 * @param array<int,array{owner:string,name:string,ref:string,source_path:string,source_url:string,requested_ref?:string}> $repositories Candidate repositories.
	 * @return array{discovered:int,queued:int,failed:int,complete:bool,message:string}|null
	 * @throws RuntimeException When GitHub tree traversal fails.
	 */
	private function queue_tree_items( ImportSession $session, array $repositories ) {
		if ( null === $this->content_fetcher ) {
			return null;
		}

		foreach ( $repositories as $candidate ) {
			try {
				$state = $this->tree_state_item( $session, $candidate );
				if ( $this->is_tree_state_complete( $state ) ) {
					return array(
						'discovered' => 0,
						'queued'     => 0,
						'failed'     => 0,
						'complete'   => true,
						'message'    => 'GitHub repository tree files are already present in the source queue.',
					);
				}

				$entries = $this->fetch_tree_file_entries( $candidate );
				if ( empty( $entries ) ) {
					throw new RuntimeException( 'GitHub tree response did not contain importable files.' );
				}

				$metadata          = $state->get_metadata();
				$cursor            = isset( $metadata['github_tree_cursor'] ) ? (string) $metadata['github_tree_cursor'] : '';
				$remaining_entries = $this->tree_entries_after_cursor( $entries, $cursor );
				if ( empty( $remaining_entries ) ) {
					$this->mark_tree_state_complete( $session, array( $candidate ) );
					return array(
						'discovered' => 0,
						'queued'     => 0,
						'failed'     => 0,
						'complete'   => true,
						'message'    => 'GitHub repository tree files are already present in the source queue.',
					);
				}

				$page_entries = $this->expand_tree_entries_with_markdown_links( $candidate, array_slice( $remaining_entries, 0, self::TREE_BLOB_LIMIT ) );
				$items        = array();
				foreach ( $page_entries as $entry ) {
					$item = $this->build_tree_file_item( $session, $candidate, $entry );
					if ( null !== $item ) {
						$items[] = $item;
					}
				}

				foreach ( $items as $item ) {
					$this->store->save_source_item( $item );
				}

				$is_complete = count( $remaining_entries ) <= self::TREE_BLOB_LIMIT;
				$last_entry  = end( $page_entries );
				$metadata    = array_merge(
					$metadata,
					array(
						'github_tree_status'       => $is_complete ? 'complete' : 'partial',
						'github_tree_cursor'       => is_array( $last_entry ) && isset( $last_entry['path'] ) ? (string) $last_entry['path'] : $cursor,
						'github_tree_total_files'  => count( $entries ),
						'github_tree_queued_files' => ( isset( $metadata['github_tree_queued_files'] ) ? (int) $metadata['github_tree_queued_files'] : 0 ) + count( $items ),
					)
				);

				if ( $is_complete ) {
					unset( $metadata['github_rate_limit'] );
					$this->store->save_source_item( $state->with_status( ImportSourceItem::STATUS_SKIPPED )->with_replaced_metadata( $metadata ) );
					$this->record_event(
						$session,
						'github.tree_queued',
						'GitHub repository tree files were queued without downloading a repository zipball.',
						$candidate,
						array(
							'files' => count( $items ),
						)
					);
				} else {
					$this->store->save_source_item( $state->with_status( ImportSourceItem::STATUS_PROCESSING )->with_replaced_metadata( $metadata ) );
					$this->record_event(
						$session,
						'github.tree_progress',
						'GitHub repository tree files were partially queued and can resume from the stored cursor.',
						$candidate,
						array(
							'files'  => count( $items ),
							'cursor' => $metadata['github_tree_cursor'],
						)
					);

					if ( $this->controls->should_simulate_fatal_after_github_tree_cursor() ) {
						$this->record_event(
							$session,
							'runner.simulated_fatal_after_github_tree_cursor',
							'Runner is terminating PHP after a durable GitHub tree cursor write for recovery testing.',
							$candidate,
							array(
								'files'  => count( $items ),
								'cursor' => $metadata['github_tree_cursor'],
							)
						);

						exit( 126 );
					}
				}

				return array(
					'discovered' => count( $items ),
					'queued'     => count( $items ),
					'failed'     => 0,
					'complete'   => $is_complete,
					'message'    => $is_complete ? 'GitHub repository tree files were queued without downloading a zipball.' : 'GitHub repository tree files were partially queued without downloading a zipball.',
				);
			} catch ( ImportRemoteRateLimitException $exception ) {
				return $this->defer_rate_limited_tree( $session, $candidate, $exception );
			} catch ( RuntimeException $exception ) {
				if ( $this->tree_state_has_progress( $session, $candidate ) ) {
					return $this->fail_partial_tree( $session, $candidate, $exception );
				}
				continue;
			}
		}

		return null;
	}

	/**
	 * Stores a retryable GitHub tree/blob API rate limit.
	 *
	 * @param ImportSession                                                                                         $session   Session.
	 * @param array{owner:string,name:string,ref:string,source_path:string,source_url:string,requested_ref?:string} $repo Repository data.
	 * @param ImportRemoteRateLimitException                                                                        $exception Rate-limit diagnostic.
	 * @return array{discovered:int,queued:int,failed:int,complete:bool,message:string}
	 */
	private function defer_rate_limited_tree( ImportSession $session, array $repo, ImportRemoteRateLimitException $exception ) {
		$item     = $this->tree_state_item( $session, $repo );
		$metadata = $this->github_rate_limited_metadata( $item->get_metadata(), $exception );
		$this->store->save_source_item( $item->with_status( ImportSourceItem::STATUS_PROCESSING )->with_replaced_metadata( $metadata ) );
		$this->record_event(
			$session,
			'github.tree_rate_limited',
			'GitHub tree/blob API asked the importer to back off; traversal will retry after the stored delay.',
			$repo,
			array(
				'url'                 => $exception->get_url(),
				'status_code'         => $exception->get_status_code(),
				'retry_after_header'  => $exception->get_retry_after_header(),
				'retry_after_seconds' => $exception->get_retry_after_seconds(),
			),
			ImportProgressEvent::LEVEL_WARNING
		);

		return array(
			'discovered' => 0,
			'queued'     => 0,
			'failed'     => 0,
			'complete'   => false,
			'message'    => 'GitHub tree/blob API is rate limited; traversal will retry after the stored backoff delay.',
		);
	}

	/**
	 * Returns a traversal summary when a stored GitHub backoff window is active.
	 *
	 * @param ImportSession                                                                                                    $session      Session.
	 * @param array<int,array{owner:string,name:string,ref:string,source_path:string,source_url:string,requested_ref?:string}> $repositories Candidate repositories.
	 * @return array{discovered:int,queued:int,failed:int,complete:bool,message:string}|null
	 */
	private function deferred_rate_limit_summary( ImportSession $session, array $repositories ) {
		foreach ( $repositories as $repo ) {
			$item = $this->store->find_source_item( $session->get_id(), $this->tree_state_key( $repo ) );
			if ( null === $item ) {
				continue;
			}

			$metadata = $item->get_metadata();
			if ( empty( $metadata['github_rate_limit'] ) || ! is_array( $metadata['github_rate_limit'] ) || empty( $metadata['github_rate_limit']['next_retry_unix'] ) ) {
				continue;
			}

			$next_retry = (int) $metadata['github_rate_limit']['next_retry_unix'];
			if ( $next_retry > time() ) {
				return array(
					'discovered' => 0,
					'queued'     => 0,
					'failed'     => 0,
					'complete'   => false,
					'message'    => 'GitHub tree/blob API is rate limited; next retry is scheduled for ' . gmdate( 'c', $next_retry ) . '.',
				);
			}

			unset( $metadata['github_rate_limit'] );
			$metadata['github_tree_status'] = 'retrying';
			$this->store->save_source_item( $item->with_status( ImportSourceItem::STATUS_PROCESSING )->with_replaced_metadata( $metadata ) );
		}

		return null;
	}

	/**
	 * Returns whether a GitHub tree state item is already complete.
	 *
	 * @param ImportSourceItem $item Tree state item.
	 * @return bool
	 */
	private function is_tree_state_complete( ImportSourceItem $item ) {
		$metadata = $item->get_metadata();

		return ImportSourceItem::STATUS_SKIPPED === $item->get_status() && isset( $metadata['github_tree_status'] ) && 'complete' === $metadata['github_tree_status'];
	}

	/**
	 * Returns whether a GitHub tree state item has saved partial progress.
	 *
	 * @param ImportSession                                                                                         $session Session.
	 * @param array{owner:string,name:string,ref:string,source_path:string,source_url:string,requested_ref?:string} $repo    Repository data.
	 * @return bool
	 */
	private function tree_state_has_progress( ImportSession $session, array $repo ) {
		$item = $this->store->find_source_item( $session->get_id(), $this->tree_state_key( $repo ) );
		if ( null === $item ) {
			return false;
		}

		$metadata = $item->get_metadata();

		return ! empty( $metadata['github_tree_cursor'] ) || ! empty( $metadata['github_tree_queued_files'] );
	}

	/**
	 * Marks a partially queued GitHub tree traversal failed without zipball fallback.
	 *
	 * @param ImportSession                                                                                         $session   Session.
	 * @param array{owner:string,name:string,ref:string,source_path:string,source_url:string,requested_ref?:string} $repo      Repository data.
	 * @param RuntimeException                                                                                      $exception Failure.
	 * @return array{discovered:int,queued:int,failed:int,complete:bool,message:string}
	 */
	private function fail_partial_tree( ImportSession $session, array $repo, RuntimeException $exception ) {
		$item     = $this->tree_state_item( $session, $repo );
		$metadata = $item->get_metadata();

		$metadata['github_tree_status'] = 'failed';
		$metadata['error']              = $exception->getMessage();
		$this->store->save_source_item( $item->with_status( ImportSourceItem::STATUS_FAILED )->with_replaced_metadata( $metadata ) );
		$this->record_event(
			$session,
			'github.tree_failed',
			'GitHub tree/blob traversal failed after partial progress; zipball fallback is disabled for GitHub imports.',
			$repo,
			array(
				'error' => $exception->getMessage(),
			),
			ImportProgressEvent::LEVEL_WARNING
		);

		return array(
			'discovered' => 0,
			'queued'     => 0,
			'failed'     => 1,
			'complete'   => true,
			'message'    => $exception->getMessage(),
		);
	}

	/**
	 * Builds metadata for a retryable GitHub tree/blob API rate limit.
	 *
	 * @param array<string,mixed>            $metadata  Existing metadata.
	 * @param ImportRemoteRateLimitException $exception Rate-limit diagnostic.
	 * @return array<string,mixed>
	 */
	private function github_rate_limited_metadata( array $metadata, ImportRemoteRateLimitException $exception ) {
		$retry_at = time() + $exception->get_retry_after_seconds();

		$metadata['github_tree_status']         = 'rate-limited';
		$metadata['github_rate_limit']          = array(
			'url'                 => $exception->get_url(),
			'status_code'         => $exception->get_status_code(),
			'retry_after_header'  => $exception->get_retry_after_header(),
			'retry_after_seconds' => $exception->get_retry_after_seconds(),
			'next_retry_at'       => gmdate( 'c', $retry_at ),
			'next_retry_unix'     => $retry_at,
		);
		$metadata['github_rate_limit_warnings'] = $this->append_github_rate_limit_warning(
			isset( $metadata['github_rate_limit_warnings'] ) && is_array( $metadata['github_rate_limit_warnings'] ) ? $metadata['github_rate_limit_warnings'] : array(),
			$metadata['github_rate_limit']
		);

		return $metadata;
	}

	/**
	 * Appends a bounded GitHub rate-limit warning history item.
	 *
	 * @param array<int,array<string,mixed>> $warnings Existing warnings.
	 * @param array<string,mixed>            $warning  New warning.
	 * @return array<int,array<string,mixed>>
	 */
	private function append_github_rate_limit_warning( array $warnings, array $warning ) {
		$warnings[] = array(
			'url'                 => isset( $warning['url'] ) ? (string) $warning['url'] : '',
			'status_code'         => isset( $warning['status_code'] ) ? (int) $warning['status_code'] : 0,
			'retry_after_header'  => isset( $warning['retry_after_header'] ) ? (string) $warning['retry_after_header'] : '',
			'retry_after_seconds' => isset( $warning['retry_after_seconds'] ) ? (int) $warning['retry_after_seconds'] : 0,
			'next_retry_at'       => isset( $warning['next_retry_at'] ) ? (string) $warning['next_retry_at'] : '',
			'error'               => isset( $warning['error'] ) ? (string) $warning['error'] : '',
		);

		return array_slice( $warnings, -10 );
	}

	/**
	 * Marks any GitHub tree traversal state items terminal after another path succeeds.
	 *
	 * @param ImportSession                                                                                                    $session      Session.
	 * @param array<int,array{owner:string,name:string,ref:string,source_path:string,source_url:string,requested_ref?:string}> $repositories Candidate repositories.
	 * @return void
	 */
	private function mark_tree_state_complete( ImportSession $session, array $repositories ) {
		foreach ( $repositories as $repo ) {
			$item = $this->store->find_source_item( $session->get_id(), $this->tree_state_key( $repo ) );
			if ( null === $item ) {
				continue;
			}

			$metadata = $item->get_metadata();
			unset( $metadata['github_rate_limit'] );
			$metadata['github_tree_status'] = 'complete';
			$this->store->save_source_item( $item->with_status( ImportSourceItem::STATUS_SKIPPED )->with_replaced_metadata( $metadata ) );
		}
	}

	/**
	 * Builds or loads a durable GitHub tree traversal state item.
	 *
	 * @param ImportSession                                                                                         $session Session.
	 * @param array{owner:string,name:string,ref:string,source_path:string,source_url:string,requested_ref?:string} $repo Repository data.
	 * @return ImportSourceItem
	 */
	private function tree_state_item( ImportSession $session, array $repo ) {
		$existing = $this->store->find_source_item( $session->get_id(), $this->tree_state_key( $repo ) );
		if ( null !== $existing ) {
			return $existing;
		}

		return ImportSourceItem::queued(
			$session->get_id(),
			$this->tree_state_key( $repo ),
			null,
			$repo['source_url'],
			$repo['source_url'],
			ImportSourceItem::TYPE_DIRECTORY,
			array(
				'github_owner'         => $repo['owner'],
				'github_repository'    => $repo['name'],
				'github_ref'           => $repo['ref'],
				'github_requested_ref' => isset( $repo['requested_ref'] ) ? $repo['requested_ref'] : $repo['ref'],
				'github_source_url'    => $repo['source_url'],
				'github_source_path'   => $repo['source_path'],
				'github_tree_status'   => 'pending',
			)
		);
	}

	/**
	 * Fetches a GitHub tree response.
	 *
	 * @param array{owner:string,name:string,ref:string,source_path:string,source_url:string} $repo Repository data.
	 * @return array<string,mixed>
	 * @throws RuntimeException When the GitHub tree response is malformed.
	 */
	private function fetch_tree( array $repo ) {
		$tree = $this->content_fetcher->fetch_json( $this->tree_api_url( $repo ) );

		if ( ! is_array( $tree ) || ! isset( $tree['tree'] ) || ! is_array( $tree['tree'] ) ) {
			throw new RuntimeException( 'GitHub tree response was malformed.' );
		}

		return $tree;
	}

	/**
	 * Fetches importable GitHub tree entries for a candidate repository path.
	 *
	 * @param array{owner:string,name:string,ref:string,source_path:string,source_url:string} $repo Repository data.
	 * @return array<int,array{path:string,url:string,size:int}>
	 * @throws RuntimeException When no tree or contents fallback can be loaded.
	 */
	private function fetch_tree_file_entries( array $repo ) {
		try {
			$tree = $this->fetch_tree( $repo );

			if ( ! empty( $tree['truncated'] ) ) {
				if ( '' !== trim( (string) $repo['source_path'], '/' ) ) {
					return $this->fetch_contents_file_entries( $repo );
				}

				throw new RuntimeException( 'GitHub tree response was truncated; sparse Git or Contents API traversal is required.' );
			}

			$entries = $this->filter_tree_file_entries( $tree, $repo['source_path'] );
			if ( ! empty( $entries ) || '' === trim( (string) $repo['source_path'], '/' ) ) {
				return $entries;
			}
		} catch ( RuntimeException $exception ) {
			if ( '' === trim( (string) $repo['source_path'], '/' ) ) {
				throw $exception;
			}
		}

		return $this->fetch_contents_file_entries( $repo );
	}

	/**
	 * Fetches importable files under a repository path through the Contents API.
	 *
	 * @param array{owner:string,name:string,ref:string,source_path:string,source_url:string} $repo Repository data.
	 * @return array<int,array{path:string,url:string,size:int}>
	 * @throws RuntimeException When the contents response is malformed or too large.
	 */
	private function fetch_contents_file_entries( array $repo ) {
		$path    = $this->normalize_source_path( $repo['source_path'] );
		$entries = array();

		if ( '' === $path ) {
			throw new RuntimeException( 'GitHub contents traversal requires a repository path.' );
		}

		$this->collect_contents_file_entries( $repo, $path, $entries );

		usort(
			$entries,
			function ( $a, $b ) {
				return strcmp( $a['path'], $b['path'] );
			}
		);

		return $entries;
	}

	/**
	 * Recursively collects GitHub Contents API file entries.
	 *
	 * @param array{owner:string,name:string,ref:string,source_path:string,source_url:string} $repo Repository data.
	 * @param string                                                                          $path Repository-relative path.
	 * @param array<int,array{path:string,url:string,size:int}>                               $entries Collected entries.
	 * @return void
	 * @throws RuntimeException When the contents response is malformed or too large.
	 */
	private function collect_contents_file_entries( array $repo, $path, array &$entries ) {
		if ( self::CONTENTS_API_FILE_LIMIT <= count( $entries ) ) {
			throw new RuntimeException( 'GitHub contents traversal exceeded the importer repository file limit.' );
		}

		$contents = $this->content_fetcher->fetch_json( $this->contents_api_url( $repo, $path ) );

		if ( $this->is_contents_file_entry( $contents ) ) {
			$entries[] = $this->contents_file_entry_to_tree_entry( $contents );
			return;
		}

		if ( ! is_array( $contents ) ) {
			throw new RuntimeException( 'GitHub contents response was malformed.' );
		}

		foreach ( $contents as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['type'] ) || empty( $entry['path'] ) ) {
				continue;
			}

			if ( 'file' === (string) $entry['type'] && $this->is_contents_file_entry( $entry ) ) {
				$entries[] = $this->contents_file_entry_to_tree_entry( $entry );
				continue;
			}

			if ( 'dir' === (string) $entry['type'] ) {
				$this->collect_contents_file_entries( $repo, $this->normalize_source_path( (string) $entry['path'] ), $entries );
			}
		}
	}

	/**
	 * Returns whether a Contents API entry points to a fetchable file blob.
	 *
	 * @param mixed $entry Contents API entry.
	 * @return bool
	 */
	private function is_contents_file_entry( $entry ) {
		return is_array( $entry )
			&& isset( $entry['type'], $entry['path'], $entry['git_url'] )
			&& 'file' === (string) $entry['type']
			&& '' !== $this->normalize_source_path( (string) $entry['path'] )
			&& '' !== trim( (string) $entry['git_url'] );
	}

	/**
	 * Converts a Contents API file entry into the tree entry shape.
	 *
	 * @param array<string,mixed> $entry Contents API entry.
	 * @return array{path:string,url:string,size:int}
	 */
	private function contents_file_entry_to_tree_entry( array $entry ) {
		return array(
			'path' => $this->normalize_source_path( (string) $entry['path'] ),
			'url'  => (string) $entry['git_url'],
			'size' => isset( $entry['size'] ) ? max( 0, (int) $entry['size'] ) : 0,
		);
	}

	/**
	 * Adds local Markdown files linked from discovered GitHub Markdown files.
	 *
	 * @param array{owner:string,name:string,ref:string,source_path:string,source_url:string} $repo Repository data.
	 * @param array<int,array{path:string,url:string,size:int,content?:string}>               $entries Tree entries.
	 * @return array<int,array{path:string,url:string,size:int,content?:string}>
	 */
	private function expand_tree_entries_with_markdown_links( array $repo, array $entries ) {
		$indexed = array();
		$queue   = array();

		foreach ( $entries as $entry ) {
			$path = $this->normalize_source_path( isset( $entry['path'] ) ? (string) $entry['path'] : '' );
			if ( '' === $path ) {
				continue;
			}
			$entry['path']    = $path;
			$indexed[ $path ] = $entry;
			$queue[]          = $path;
		}

		$indexed_count = count( $indexed );
		while ( ! empty( $queue ) && $indexed_count < self::CONTENTS_API_FILE_LIMIT ) {
			$path = array_shift( $queue );
			if ( ! isset( $indexed[ $path ] ) || ! $this->is_markdown_repository_path( $path ) ) {
				continue;
			}

			$content = isset( $indexed[ $path ]['content'] ) ? (string) $indexed[ $path ]['content'] : '';
			if ( '' === $content ) {
				try {
					$content = $this->decode_blob_content( $this->content_fetcher->fetch_json( $indexed[ $path ]['url'] ) );
				} catch ( RuntimeException $exception ) {
					$indexed[ $path ]['content_fetch_failed'] = true;
					continue;
				}
				$indexed[ $path ]['content'] = $content;
			}

			foreach ( $this->local_markdown_paths_from_markdown( $content, $path ) as $target_path ) {
				if ( isset( $indexed[ $target_path ] ) || count( $indexed ) >= self::CONTENTS_API_FILE_LIMIT ) {
					continue;
				}

				try {
					$entry = $this->fetch_contents_file_entry( $repo, $target_path );
				} catch ( RuntimeException $exception ) {
					continue;
				}

				if ( null === $entry ) {
					continue;
				}

				$indexed[ $entry['path'] ] = $entry;
				$indexed_count             = count( $indexed );
				$queue[]                   = $entry['path'];
			}
		}

		$entries = array_values( $indexed );
		usort(
			$entries,
			function ( $a, $b ) {
				return strcmp( $a['path'], $b['path'] );
			}
		);

		return $entries;
	}

	/**
	 * Fetches one Contents API file entry.
	 *
	 * @param array{owner:string,name:string,ref:string,source_path:string,source_url:string} $repo Repository data.
	 * @param string                                                                          $path Repository-relative path.
	 * @return array{path:string,url:string,size:int}|null
	 */
	private function fetch_contents_file_entry( array $repo, $path ) {
		$contents = $this->content_fetcher->fetch_json( $this->contents_api_url( $repo, $path ) );

		return $this->is_contents_file_entry( $contents ) ? $this->contents_file_entry_to_tree_entry( $contents ) : null;
	}

	/**
	 * Extracts local Markdown target paths from a Markdown document.
	 *
	 * @param string $content Markdown content.
	 * @param string $source_path Repository-relative source file path.
	 * @return array<int,string>
	 */
	private function local_markdown_paths_from_markdown( $content, $source_path ) {
		$paths  = array();
		$length = strlen( (string) $content );

		for ( $index = 0; $index < $length; ++$index ) {
			if ( '[' !== $content[ $index ] ) {
				continue;
			}

			$label_end = $this->find_markdown_closing_delimiter( $content, $index + 1, ']' );
			if ( null === $label_end || ! isset( $content[ $label_end + 1 ] ) || '(' !== $content[ $label_end + 1 ] ) {
				continue;
			}

			$href_start = $label_end + 2;
			$href_end   = $this->find_markdown_closing_delimiter( $content, $href_start, ')' );
			if ( null === $href_end ) {
				continue;
			}

			$href = trim( substr( $content, $href_start, $href_end - $href_start ) );
			if ( false !== strpos( $href, ' ' ) ) {
				$href = substr( $href, 0, strpos( $href, ' ' ) );
			}
			$path = $this->local_markdown_repository_path( $href, $source_path );
			if ( null !== $path ) {
				$paths[ $path ] = $path;
			}
			$index = $href_end;
		}

		return array_values( $paths );
	}

	/**
	 * Finds an unescaped Markdown closing delimiter.
	 *
	 * @param string $content Content.
	 * @param int    $offset Offset.
	 * @param string $delimiter Delimiter.
	 * @return int|null
	 */
	private function find_markdown_closing_delimiter( $content, $offset, $delimiter ) {
		$length = strlen( (string) $content );
		for ( $index = (int) $offset; $index < $length; ++$index ) {
			if ( '\\' === $content[ $index ] ) {
				++$index;
				continue;
			}
			if ( $delimiter === $content[ $index ] ) {
				return $index;
			}
		}

		return null;
	}

	/**
	 * Converts a Markdown href into a repository path when it targets a local Markdown file.
	 *
	 * @param string $href Href.
	 * @param string $source_path Repository-relative source file path.
	 * @return string|null
	 */
	private function local_markdown_repository_path( $href, $source_path ) {
		$href = trim( html_entity_decode( (string) $href, ENT_QUOTES, 'UTF-8' ), '<>' );

		if ( '' === $href || '#' === substr( $href, 0, 1 ) || '//' === substr( $href, 0, 2 ) ) {
			return null;
		}

		if ( preg_match( '/^[a-z][a-z0-9+.-]*:/i', $href ) ) {
			return null;
		}

		$parts = parse_url( $href ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- WordPress is not loaded in importer unit tests.
		if ( false === $parts || empty( $parts['path'] ) ) {
			return null;
		}

		$path = (string) $parts['path'];
		if ( ! $this->is_markdown_repository_path( $path ) ) {
			return null;
		}

		return '/' === substr( $path, 0, 1 )
			? $this->normalize_source_path( ltrim( $path, '/' ) )
			: $this->normalize_repository_link_path( dirname( $source_path ) . '/' . $path );
	}

	/**
	 * Normalizes a repository path that may contain relative segments.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private function normalize_repository_link_path( $path ) {
		$segments = array();
		foreach ( explode( '/', str_replace( '\\', '/', (string) $path ) ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				array_pop( $segments );
				continue;
			}
			$segments[] = $segment;
		}

		return implode( '/', $segments );
	}

	/**
	 * Returns whether a repository path points to Markdown.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private function is_markdown_repository_path( $path ) {
		$extension = strtolower( pathinfo( (string) $path, PATHINFO_EXTENSION ) );

		return in_array( $extension, array( 'md', 'markdown', 'mdown' ), true );
	}

	/**
	 * Filters tree entries to importable files under the requested source path.
	 *
	 * @param array<string,mixed> $tree        GitHub tree response.
	 * @param string              $source_path Repository-relative subtree path.
	 * @return array<int,array{path:string,url:string,size:int}>
	 */
	private function filter_tree_file_entries( array $tree, $source_path ) {
		$entries     = array();
		$source_path = trim( (string) $source_path, '/' );
		$prefix      = '' === $source_path ? '' : $source_path . '/';

		foreach ( $tree['tree'] as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['type'], $entry['path'], $entry['url'] ) || 'blob' !== (string) $entry['type'] ) {
				continue;
			}

			$path = $this->normalize_source_path( (string) $entry['path'] );
			if ( '' === $path ) {
				continue;
			}

			if ( '' !== $prefix && 0 !== strpos( $path, $prefix ) ) {
				continue;
			}

			$entries[] = array(
				'path' => $path,
				'url'  => (string) $entry['url'],
				'size' => isset( $entry['size'] ) ? max( 0, (int) $entry['size'] ) : 0,
			);
		}

		usort(
			$entries,
			function ( $a, $b ) {
				return strcmp( $a['path'], $b['path'] );
			}
		);

		return $entries;
	}

	/**
	 * Returns tree entries after the stored path cursor.
	 *
	 * @param array<int,array{path:string,url:string,size:int}> $entries Sorted tree entries.
	 * @param string                                            $cursor  Last processed repository path.
	 * @return array<int,array{path:string,url:string,size:int}>
	 */
	private function tree_entries_after_cursor( array $entries, $cursor ) {
		$cursor = (string) $cursor;
		if ( '' === $cursor ) {
			return $entries;
		}

		$remaining = array();
		foreach ( $entries as $entry ) {
			if ( strcmp( $entry['path'], $cursor ) > 0 ) {
				$remaining[] = $entry;
			}
		}

		return $remaining;
	}

	/**
	 * Fetches one GitHub blob and builds a discovered local source file.
	 *
	 * @param ImportSession                                                                                         $session Session.
	 * @param array{owner:string,name:string,ref:string,source_path:string,source_url:string,requested_ref?:string} $repo Repository data.
	 * @param array{path:string,url:string,size:int}                                                                $entry Tree entry.
	 * @return ImportSourceItem|null Source item to persist, or null when it already exists.
	 * @throws RuntimeException When the blob cannot be fetched, decoded, cached, or stored.
	 */
	private function build_tree_file_item( ImportSession $session, array $repo, array $entry ) {
		$item_key = $this->tree_item_key( $repo, $entry['path'] );
		$existing = $this->store->find_source_item( $session->get_id(), $item_key );
		if ( null !== $existing ) {
			return null;
		}

		if ( ! empty( $entry['content_fetch_failed'] ) ) {
			throw new RuntimeException( 'GitHub blob content could not be loaded.' );
		}

		$content = isset( $entry['content'] ) ? (string) $entry['content'] : $this->decode_blob_content( $this->content_fetcher->fetch_json( $entry['url'] ) );
		$target  = $this->tree_cache_path( $session, $repo, $entry['path'] );
		$this->cache_directory->ensure_parent_directory( $target );
		if ( false === file_put_contents( $target, $content ) ) {
			throw new RuntimeException( 'GitHub blob cache file could not be written.' );
		}

		$relative_path = $this->tree_relative_path( $repo['source_path'], $entry['path'] );
		$metadata      = array(
			'basename'             => basename( $relative_path ),
			'bytes'                => strlen( $content ),
			'extension'            => strtolower( pathinfo( $relative_path, PATHINFO_EXTENSION ) ),
			'github_owner'         => $repo['owner'],
			'github_repository'    => $repo['name'],
			'github_ref'           => $repo['ref'],
			'github_requested_ref' => isset( $repo['requested_ref'] ) ? $repo['requested_ref'] : $repo['ref'],
			'github_source_url'    => $repo['source_url'],
			'github_source_path'   => $repo['source_path'],
			'github_tree_path'     => $entry['path'],
			'github_blob_url'      => $entry['url'],
			'github_tree_fetch'    => true,
		) + $this->cache_directory->metadata_for( 'github', $target );

		return ImportSourceItem::queued(
			$session->get_id(),
			$item_key,
			null,
			$target,
			$relative_path,
			ImportSourceItem::TYPE_FILE,
			$metadata
		)->with_status( ImportSourceItem::STATUS_DISCOVERED );
	}

	/**
	 * Decodes a GitHub blob JSON response.
	 *
	 * @param array<string,mixed>|array<int,mixed> $blob Blob response.
	 * @return string
	 * @throws RuntimeException When the GitHub blob response cannot be decoded.
	 */
	private function decode_blob_content( array $blob ) {
		if ( ! isset( $blob['content'], $blob['encoding'] ) || 'base64' !== (string) $blob['encoding'] ) {
			throw new RuntimeException( 'GitHub blob response was malformed.' );
		}

		$content = base64_decode( preg_replace( '/\s+/', '', (string) $blob['content'] ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- GitHub blob API returns base64 encoded file content.
		if ( false === $content ) {
			throw new RuntimeException( 'GitHub blob content could not be decoded.' );
		}

		return $content;
	}

	/**
	 * Builds the GitHub REST tree URL.
	 *
	 * @param array{owner:string,name:string,ref:string,source_path:string,source_url:string} $repo Repository data.
	 * @return string
	 */
	private function tree_api_url( array $repo ) {
		return GitHubRepositorySourceUrl::tree_api_url( $repo );
	}

	/**
	 * Builds the GitHub Contents API URL for a repository path.
	 *
	 * @param array{owner:string,name:string,ref:string} $repo Repository data.
	 * @param string                                     $path Repository-relative path.
	 * @return string
	 */
	private function contents_api_url( array $repo, $path ) {
		$path = $this->normalize_source_path( $path );

		return GitHubRepositorySourceUrl::repository_api_url( $repo )
			. '/contents/'
			. str_replace( '%2F', '/', rawurlencode( $path ) )
			. '?ref='
			. str_replace( '%2F', '/', rawurlencode( $repo['ref'] ) );
	}

	/**
	 * Builds the durable source item key for a GitHub tree blob.
	 *
	 * @param array{owner:string,name:string,ref:string,source_path:string,source_url:string} $repo Repository data.
	 * @param string                                                                          $path Repository-relative file path.
	 * @return string
	 */
	private function tree_item_key( array $repo, $path ) {
		return 'github-blob:' . hash( 'sha256', strtolower( $repo['owner'] ) . '/' . strtolower( $repo['name'] ) . "\n" . $repo['ref'] . "\n" . $path );
	}

	/**
	 * Builds the durable source item key for a Git-fetched repository file.
	 *
	 * @param array{owner:string,name:string,ref:string,source_path:string,source_url:string} $repo Repository data.
	 * @param string                                                                          $path Repository-relative file path.
	 * @return string
	 */
	private function git_item_key( array $repo, $path ) {
		return 'github-git-blob:' . hash( 'sha256', strtolower( $repo['owner'] ) . '/' . strtolower( $repo['name'] ) . "\n" . $repo['ref'] . "\n" . $path );
	}

	/**
	 * Builds the durable source item key for GitHub tree traversal state.
	 *
	 * @param array{owner:string,name:string,ref:string,source_path:string,source_url:string} $repo Repository data.
	 * @return string
	 */
	private function tree_state_key( array $repo ) {
		return 'github-tree:' . hash( 'sha256', strtolower( $repo['owner'] ) . '/' . strtolower( $repo['name'] ) . "\n" . $repo['ref'] . "\n" . $repo['source_path'] );
	}

	/**
	 * Builds the durable source item key for Git traversal state.
	 *
	 * @param array{owner:string,name:string,ref:string,source_path:string,source_url:string} $repo Repository data.
	 * @return string
	 */
	private function git_state_key( array $repo ) {
		return 'github-git:' . hash( 'sha256', strtolower( $repo['owner'] ) . '/' . strtolower( $repo['name'] ) . "\n" . $repo['ref'] . "\n" . $repo['source_path'] );
	}

	/**
	 * Builds the local cache path for a GitHub tree blob.
	 *
	 * @param ImportSession                                                                   $session Session.
	 * @param array{owner:string,name:string,ref:string,source_path:string,source_url:string} $repo    Repository data.
	 * @param string                                                                          $path    Repository-relative file path.
	 * @return string
	 */
	private function tree_cache_path( ImportSession $session, array $repo, $path ) {
		return $this->cache_directory->path_for(
			$session->get_id(),
			'github',
			array(
				hash( 'sha256', strtolower( $repo['owner'] ) . '/' . strtolower( $repo['name'] ) . "\n" . $repo['ref'] . "\n" . $path ),
				basename( (string) $path ),
			)
		);
	}

	/**
	 * Returns the import-relative path for a tree entry.
	 *
	 * @param string $source_path Requested subtree path.
	 * @param string $entry_path  Repository-relative entry path.
	 * @return string
	 */
	private function tree_relative_path( $source_path, $entry_path ) {
		$source_path = trim( (string) $source_path, '/' );
		$entry_path  = trim( (string) $entry_path, '/' );

		if ( '' === $source_path ) {
			return $entry_path;
		}

		$prefix = $source_path . '/';

		return 0 === strpos( $entry_path, $prefix ) ? substr( $entry_path, strlen( $prefix ) ) : $entry_path;
	}

	/**
	 * Parses a supported GitHub repository URL.
	 *
	 * @param string $source Source URL.
	 * @return array{owner:string,name:string,ref:string,source_path:string,source_url:string,fallback_ref?:string,fallback_source_path?:string}|null
	 */
	private function parse_repository_url( $source ) {
		return GitHubRepositorySourceUrl::parse( $source );
	}

	/**
	 * Returns GitHub traversal candidates for a parsed URL.
	 *
	 * @param array{owner:string,name:string,ref:string,source_path:string,source_url:string,fallback_candidates?:array<int,array{ref:string,source_path:string}>} $repo Parsed repository data.
	 * @return array<int,array{owner:string,name:string,ref:string,source_path:string,source_url:string,requested_ref?:string}>
	 */
	private function candidate_repositories( array $repo ) {
		return GitHubRepositorySourceUrl::candidates( $repo );
	}

	/**
	 * Normalizes an optional path inside a repository tree.
	 *
	 * @param string $path Repository-relative path.
	 * @return string
	 */
	private function normalize_source_path( $path ) {
		return GitHubRepositorySourceUrl::normalize_source_path( $path );
	}

	/**
	 * Builds the durable source item key.
	 *
	 * @param array{owner:string,name:string,ref:string,source_path:string,source_url:string} $repo Repository data.
	 * @return string
	 */
	private function item_key( array $repo ) {
		return 'github:' . hash( 'sha256', strtolower( $repo['owner'] ) . '/' . strtolower( $repo['name'] ) . "\n" . $repo['ref'] . "\n" . $repo['source_path'] );
	}

	/**
	 * Records GitHub traversal events.
	 *
	 * @param ImportSession                                                                   $session Session.
	 * @param string                                                                          $type    Event type.
	 * @param string                                                                          $message Event message.
	 * @param array{owner:string,name:string,ref:string,source_path:string,source_url:string} $repo    Repository data.
	 * @param array<string,mixed>                                                             $context Context.
	 * @param string                                                                          $level   Event level.
	 * @return void
	 */
	private function record_event( ImportSession $session, $type, $message, array $repo, array $context, $level = ImportProgressEvent::LEVEL_INFO ) {
		$this->store->record_event(
			$session->get_id(),
			new ImportProgressEvent(
				$level,
				$type,
				$message,
				array_merge(
					array(
						'github_owner'         => $repo['owner'],
						'github_repository'    => $repo['name'],
						'github_ref'           => $repo['ref'],
						'github_requested_ref' => isset( $repo['requested_ref'] ) ? $repo['requested_ref'] : $repo['ref'],
						'github_source_path'   => $repo['source_path'],
					),
					$context
				)
			)
		);
	}
}
