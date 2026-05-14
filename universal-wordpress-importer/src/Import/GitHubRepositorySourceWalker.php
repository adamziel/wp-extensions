<?php
/**
 * GitHub repository source walker.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Remote repository archives are cached as importer-managed files.

use RuntimeException;

/**
 * Seeds GitHub repository URLs into the durable source queue as zip archives.
 */
final class GitHubRepositorySourceWalker {
	const MAX_ARCHIVE_BYTES = 268435456;
	const TREE_BLOB_LIMIT   = 25;

	/**
	 * Durable store.
	 *
	 * @var WordPressImportSessionStore
	 */
	private $store;

	/**
	 * Remote archive fetcher.
	 *
	 * @var ImportRemoteArchiveFetcherInterface
	 */
	private $fetcher;

	/**
	 * Remote JSON fetcher for GitHub tree/blob APIs.
	 *
	 * @var ImportRemoteContentFetcherInterface|null
	 */
	private $content_fetcher;

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
	 * @param ImportRemoteArchiveFetcherInterface|null $fetcher  Optional fetcher.
	 * @param string|ImportCacheDirectory|null         $cache_root Optional cache root.
	 * @param ImportRemoteContentFetcherInterface|null $content_fetcher Optional GitHub tree/blob fetcher.
	 * @param ImportRunnerControls|null                $controls Optional hidden test controls.
	 */
	public function __construct( WordPressImportSessionStore $store, ImportRemoteArchiveFetcherInterface $fetcher = null, $cache_root = null, ImportRemoteContentFetcherInterface $content_fetcher = null, ImportRunnerControls $controls = null ) {
		$this->store           = $store;
		$this->fetcher         = null === $fetcher ? new WordPressRemoteArchiveFetcher() : $fetcher;
		$this->content_fetcher = $content_fetcher;
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

		$existing_archive_summary = $this->existing_archive_summary( $session, $repositories );
		if ( null !== $existing_archive_summary ) {
			return $existing_archive_summary;
		}

		$tree_summary = $this->queue_tree_items( $session, $repositories );
		if ( null !== $tree_summary ) {
			return $tree_summary;
		}

		$failed_items = array();

		foreach ( $repositories as $candidate ) {
			$existing = $this->store->find_source_item( $session->get_id(), $this->item_key( $candidate ) );
			if ( null !== $existing ) {
				if ( ImportSourceItem::STATUS_FAILED === $existing->get_status() ) {
					$failed_items[ $this->item_key( $candidate ) ] = $existing;
					continue;
				}

				$summary['complete']   = ImportSourceItem::STATUS_FAILED !== $existing->get_status();
				$summary['message']    = 'GitHub repository archive is already present in the source queue.';
				$summary['discovered'] = 1;
				$summary['failed']     = 0;
				return $summary;
			}
		}

		$last_exception = null;
		$last_repo      = $repo;
		$last_target    = $repo['source_url'];

		foreach ( $repositories as $candidate ) {
			$item_key = $this->item_key( $candidate );
			$target   = $candidate['source_url'];

			try {
				if ( isset( $failed_items[ $item_key ] ) ) {
					$previous_metadata = $failed_items[ $item_key ]->get_metadata();
					$this->record_event(
						$session,
						'github.archive_retrying',
						'Retrying a previously failed GitHub repository archive download.',
						$candidate,
						array(
							'previous_error' => isset( $previous_metadata['error'] ) ? (string) $previous_metadata['error'] : '',
						)
					);
				}

				$target = $this->cache_path( $session, $candidate );
				$this->cache_directory->ensure_parent_directory( $target );
				$fetch = $this->fetcher->fetch( $this->zipball_api_url( $candidate ), $target );

				if ( ! is_file( $target ) || ! is_readable( $target ) ) {
					throw new RuntimeException( 'GitHub archive cache file is not readable after download.' );
				}

				if ( self::MAX_ARCHIVE_BYTES < filesize( $target ) ) {
					unlink( $target );
					throw new RuntimeException( 'GitHub archive exceeds the importer repository archive size limit.' );
				}

				$this->store->save_source_item(
					ImportSourceItem::queued(
						$session->get_id(),
						$item_key,
						null,
						$target,
						$candidate['owner'] . '/' . $candidate['name'] . '.zip',
						ImportSourceItem::TYPE_FILE,
						array(
							'basename'                   => $candidate['name'] . '.zip',
							'bytes'                      => filesize( $target ),
							'extension'                  => 'zip',
							'github_owner'               => $candidate['owner'],
							'github_repository'          => $candidate['name'],
							'github_ref'                 => $candidate['ref'],
							'github_requested_ref'       => isset( $candidate['requested_ref'] ) ? $candidate['requested_ref'] : $candidate['ref'],
							'github_source_url'          => $candidate['source_url'],
							'github_source_path'         => $candidate['source_path'],
							'github_zipball'             => $this->zipball_api_url( $candidate ),
							'github_fetch'               => $fetch,
							'github_retry_count'         => $this->retry_count( isset( $failed_items[ $item_key ] ) ? $failed_items[ $item_key ] : null ),
							'archive_entry_prefix'       => $candidate['source_path'],
							'archive_strip_root_segment' => '' === $candidate['source_path'] ? false : true,
						)
						+ $this->cache_directory->metadata_for( 'github', $target )
					)->with_status( ImportSourceItem::STATUS_DISCOVERED )
				);
				$this->record_event(
					$session,
					'github.archive_downloaded',
					'GitHub repository archive was downloaded into the source queue.',
					$candidate,
					array(
						'bytes'      => filesize( $target ),
						'cache_path' => $target,
					)
				);
				$this->mark_tree_state_complete( $session, $repositories );
				$summary['discovered'] = 1;
				$summary['queued']     = 1;
				$summary['complete']   = true;
				$summary['message']    = 'GitHub repository archive was downloaded and queued for zip traversal.';
				return $summary;
			} catch ( RuntimeException $exception ) {
				$last_exception = $exception;
				$last_repo      = $candidate;
				$last_target    = $target;
			}
		}

		$message = null === $last_exception ? 'GitHub archive download failed.' : $last_exception->getMessage();
		$this->store->save_source_item(
			ImportSourceItem::queued(
				$session->get_id(),
				$this->item_key( $last_repo ),
				null,
				$last_target,
				$last_repo['owner'] . '/' . $last_repo['name'] . '.zip',
				ImportSourceItem::TYPE_FILE,
				array(
					'extension'            => 'zip',
					'github_owner'         => $last_repo['owner'],
					'github_repository'    => $last_repo['name'],
					'github_ref'           => $last_repo['ref'],
					'github_requested_ref' => isset( $last_repo['requested_ref'] ) ? $last_repo['requested_ref'] : $last_repo['ref'],
					'github_source_url'    => $last_repo['source_url'],
					'github_source_path'   => $last_repo['source_path'],
					'error'                => $message,
					'github_retry_count'   => $this->retry_count( isset( $failed_items[ $this->item_key( $last_repo ) ] ) ? $failed_items[ $this->item_key( $last_repo ) ] : null ),
				)
			)->with_status( ImportSourceItem::STATUS_FAILED )
		);
		$this->record_event( $session, 'github.archive_failed', $message, $last_repo, array() );
		$summary['failed']   = 1;
		$summary['complete'] = true;
		$summary['message']  = $message;

		return $summary;
	}

	/**
	 * Returns a traversal summary when an archive source item already exists.
	 *
	 * @param ImportSession                                                                                                    $session      Session.
	 * @param array<int,array{owner:string,name:string,ref:string,source_path:string,source_url:string,requested_ref?:string}> $repositories Candidate repositories.
	 * @return array{discovered:int,queued:int,failed:int,complete:bool,message:string}|null
	 */
	private function existing_archive_summary( ImportSession $session, array $repositories ) {
		foreach ( $repositories as $candidate ) {
			$existing = $this->store->find_source_item( $session->get_id(), $this->item_key( $candidate ) );
			if ( null === $existing || ImportSourceItem::STATUS_FAILED === $existing->get_status() ) {
				continue;
			}

			return array(
				'discovered' => 1,
				'queued'     => 0,
				'failed'     => 0,
				'complete'   => true,
				'message'    => 'GitHub repository archive is already present in the source queue.',
			);
		}

		return null;
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

				$tree = $this->fetch_tree( $candidate );

				if ( ! empty( $tree['truncated'] ) ) {
					throw new RuntimeException( 'GitHub tree response was truncated; falling back to archive traversal.' );
				}

				$entries = $this->filter_tree_file_entries( $tree, $candidate['source_path'] );
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

				$page_entries = array_slice( $remaining_entries, 0, self::TREE_BLOB_LIMIT );
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
						'GitHub repository tree files were queued without downloading a repository archive.',
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
					'message'    => $is_complete ? 'GitHub repository tree files were queued without downloading an archive.' : 'GitHub repository tree files were partially queued without downloading an archive.',
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
	 * Marks a partially queued GitHub tree traversal failed without archive fallback.
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
			'GitHub tree/blob traversal failed after partial progress; archive fallback was skipped to avoid duplicate imported files.',
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

		$blob    = $this->content_fetcher->fetch_json( $entry['url'] );
		$content = $this->decode_blob_content( $blob );
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
		return 'https://api.github.com/repos/' . rawurlencode( $repo['owner'] ) . '/' . rawurlencode( $repo['name'] ) . '/git/trees/' . str_replace( '%2F', '/', rawurlencode( $repo['ref'] ) ) . '?recursive=1';
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
	 * Builds the durable source item key for GitHub tree traversal state.
	 *
	 * @param array{owner:string,name:string,ref:string,source_path:string,source_url:string} $repo Repository data.
	 * @return string
	 */
	private function tree_state_key( array $repo ) {
		return 'github-tree:' . hash( 'sha256', strtolower( $repo['owner'] ) . '/' . strtolower( $repo['name'] ) . "\n" . $repo['ref'] . "\n" . $repo['source_path'] );
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
		$source = trim( (string) $source );
		$parts  = parse_url( $source );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) || 'github.com' !== strtolower( $parts['host'] ) ) {
			return null;
		}

		$path = isset( $parts['path'] ) ? trim( (string) $parts['path'], '/' ) : '';

		if ( '' === $path ) {
			return null;
		}

		$segments = explode( '/', $path );

		if ( count( $segments ) < 2 ) {
			return null;
		}

		$owner = $this->normalize_slug( $segments[0] );
		$name  = $this->normalize_slug( preg_replace( '/\.git$/', '', $segments[1] ) );

		if ( '' === $owner || '' === $name ) {
			return null;
		}

		$ref         = 'HEAD';
		$source_path = '';
		$fallbacks   = array();

		if ( isset( $segments[2] ) && 'tree' === $segments[2] && isset( $segments[3] ) ) {
			$tree_segments = array_slice( $segments, 3 );
			$ref           = implode( '/', $tree_segments );
			for ( $length = count( $tree_segments ) - 1; $length >= 1; --$length ) {
				$fallback_path = $this->normalize_source_path( implode( '/', array_slice( $tree_segments, $length ) ) );
				if ( '' === $fallback_path ) {
					continue;
				}

				$fallbacks[] = array(
					'ref'         => implode( '/', array_slice( $tree_segments, 0, $length ) ),
					'source_path' => $fallback_path,
				);
			}
		} elseif ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
			if ( isset( $query['ref'] ) && '' !== trim( (string) $query['ref'] ) ) {
				$ref = trim( (string) $query['ref'] );
			}
			if ( isset( $query['path'] ) && '' !== trim( (string) $query['path'] ) ) {
				$source_path = $this->normalize_source_path( (string) $query['path'] );
			}
		}

		$repo = array(
			'owner'       => $owner,
			'name'        => $name,
			'ref'         => $this->normalize_ref( $ref ),
			'source_path' => $source_path,
			'source_url'  => $source,
		);

		if ( ! empty( $fallbacks ) ) {
			$repo['fallback_candidates'] = $fallbacks;
		}

		return $repo;
	}

	/**
	 * Returns GitHub archive fetch candidates for a parsed URL.
	 *
	 * @param array{owner:string,name:string,ref:string,source_path:string,source_url:string,fallback_candidates?:array<int,array{ref:string,source_path:string}>} $repo Parsed repository data.
	 * @return array<int,array{owner:string,name:string,ref:string,source_path:string,source_url:string,requested_ref?:string}>
	 */
	private function candidate_repositories( array $repo ) {
		$candidates = array(
			array(
				'owner'       => $repo['owner'],
				'name'        => $repo['name'],
				'ref'         => $repo['ref'],
				'source_path' => $repo['source_path'],
				'source_url'  => $repo['source_url'],
			),
		);
		$seen       = array( $repo['ref'] . "\n" . $repo['source_path'] => true );

		foreach ( isset( $repo['fallback_candidates'] ) ? $repo['fallback_candidates'] : array() as $fallback ) {
			$fallback_ref = $this->normalize_ref( $fallback['ref'] );
			$source_path  = $this->normalize_source_path( $fallback['source_path'] );
			$key          = $fallback_ref . "\n" . $source_path;

			if ( isset( $seen[ $key ] ) || $fallback_ref === $repo['ref'] ) {
				continue;
			}

			$candidates[] = array(
				'owner'         => $repo['owner'],
				'name'          => $repo['name'],
				'ref'           => $fallback_ref,
				'source_path'   => $source_path,
				'source_url'    => $repo['source_url'],
				'requested_ref' => $repo['ref'],
			);
			$seen[ $key ] = true;
		}

		return $candidates;
	}

	/**
	 * Normalizes owner and repository slugs.
	 *
	 * @param string $slug Slug.
	 * @return string
	 */
	private function normalize_slug( $slug ) {
		$slug = trim( (string) $slug );

		return preg_match( '/^[A-Za-z0-9_.-]+$/', $slug ) ? $slug : '';
	}

	/**
	 * Normalizes a Git ref for API use.
	 *
	 * @param string $ref Ref.
	 * @return string
	 */
	private function normalize_ref( $ref ) {
		$ref = trim( str_replace( '\\', '/', (string) $ref ), '/' );

		if ( '' === $ref || false !== strpos( $ref, '..' ) || false !== strpos( $ref, "\0" ) ) {
			return 'HEAD';
		}

		return $ref;
	}

	/**
	 * Normalizes an optional path inside a repository tree.
	 *
	 * @param string $path Repository-relative path.
	 * @return string
	 */
	private function normalize_source_path( $path ) {
		$path = trim( str_replace( '\\', '/', rawurldecode( (string) $path ) ), '/' );

		if ( '' === $path || false !== strpos( $path, "\0" ) ) {
			return '';
		}

		$parts = array();

		foreach ( explode( '/', $path ) as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part ) {
				return '';
			}
			$parts[] = $part;
		}

		return implode( '/', $parts );
	}

	/**
	 * Builds the GitHub REST zipball URL.
	 *
	 * @param array{owner:string,name:string,ref:string,source_path:string,source_url:string} $repo Repository data.
	 * @return string
	 */
	private function zipball_api_url( array $repo ) {
		return 'https://api.github.com/repos/' . rawurlencode( $repo['owner'] ) . '/' . rawurlencode( $repo['name'] ) . '/zipball/' . str_replace( '%2F', '/', rawurlencode( $repo['ref'] ) );
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
	 * Builds the local cache path for a downloaded repository archive.
	 *
	 * @param ImportSession                                                                   $session Session.
	 * @param array{owner:string,name:string,ref:string,source_path:string,source_url:string} $repo    Repository data.
	 * @return string
	 */
	private function cache_path( ImportSession $session, array $repo ) {
		return $this->cache_directory->path_for(
			$session->get_id(),
			'github',
			array( hash( 'sha256', strtolower( $repo['owner'] ) . '/' . strtolower( $repo['name'] ) . "\n" . $repo['ref'] . "\n" . $repo['source_path'] ) . '.zip' )
		);
	}

	/**
	 * Returns the next retry count for a previously failed GitHub source item.
	 *
	 * @param ImportSourceItem|null $failed_item Previously failed source item, if any.
	 * @return int Retry count.
	 */
	private function retry_count( ImportSourceItem $failed_item = null ) {
		if ( null === $failed_item ) {
			return 0;
		}

		$metadata = $failed_item->get_metadata();

		return max( 0, isset( $metadata['github_retry_count'] ) ? (int) $metadata['github_retry_count'] : 0 ) + 1;
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
