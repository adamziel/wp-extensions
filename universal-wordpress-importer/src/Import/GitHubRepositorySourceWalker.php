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
 * Seeds GitHub repository URLs into the durable source queue through sparse Git.
 */
final class GitHubRepositorySourceWalker {
	/**
	 * Durable store.
	 *
	 * @var WordPressImportSessionStore
	 */
	private $store;

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
	 * @param ImportRemoteContentFetcherInterface|null $content_fetcher Deprecated GitHub tree/blob fetcher argument, ignored for GitHub imports.
	 * @param ImportRunnerControls|null                $controls Optional hidden test controls.
	 * @param GitRepositoryFetcherInterface|null       $git_fetcher Optional Git sparse checkout fetcher.
	 */
	public function __construct( WordPressImportSessionStore $store, ImportRemoteArchiveFetcherInterface $fetcher = null, $cache_root = null, ImportRemoteContentFetcherInterface $content_fetcher = null, ImportRunnerControls $controls = null, GitRepositoryFetcherInterface $git_fetcher = null ) {
		$this->store           = $store;
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
		$git_summary  = $this->queue_git_items( $session, $repositories );
		if ( null !== $git_summary ) {
			return $git_summary;
		}

		$message = 'GitHub repository traversal could not queue files through sparse Git. GitHub imports do not use tree/blob, Contents API, or zipball fallbacks.';
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

			$this->mark_git_state_processing( $session, $candidate, $state );

			try {
				$files = $this->git_fetcher->fetch( $session, $candidate, $this->cache_directory );
			} catch ( Throwable $exception ) {
				$error_message                 = $this->git_unavailable_message( $candidate, $exception );
				$metadata                      = $state->get_metadata();
				$metadata['github_git_status'] = 'unavailable';
				$metadata['error']             = $error_message;
				$this->store->save_source_item( $state->with_status( ImportSourceItem::STATUS_SKIPPED )->with_replaced_metadata( $metadata ) );
				$this->record_event(
					$session,
					'github.git_unavailable',
					$error_message,
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
	 * Builds an operator-facing sparse Git failure message.
	 *
	 * @param array{owner:string,name:string,ref:string,source_path:string,source_url:string,requested_ref?:string} $repo      Repository data.
	 * @param Throwable                                                                                          $exception Failure.
	 * @return string Failure message.
	 */
	private function git_unavailable_message( array $repo, Throwable $exception ) {
		$path = isset( $repo['source_path'] ) && '' !== (string) $repo['source_path'] ? (string) $repo['source_path'] : '/';

		return sprintf(
			'php-toolkit Git traversal failed for ref "%1$s" at path "%2$s": %3$s The importer will try the next GitHub path candidate.',
			(string) $repo['ref'],
			$path,
			$exception->getMessage()
		);
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
	 * Marks Git traversal state processing before the remote pull begins.
	 *
	 * @param ImportSession                                                                                         $session Session.
	 * @param array{owner:string,name:string,ref:string,source_path:string,source_url:string,requested_ref?:string} $repo    Repository data.
	 * @param ImportSourceItem                                                                                      $state   State item.
	 * @return void
	 */
	private function mark_git_state_processing( ImportSession $session, array $repo, ImportSourceItem $state ) {
		$metadata = array_merge(
			$state->get_metadata(),
			array(
				'github_git_status'        => 'pulling',
				'github_git_status_detail' => 'Fetching repository files through sparse Git checkout.',
				'github_git_started_at'    => gmdate( 'c' ),
			)
		);

		$this->store->save_source_item( $state->with_status( ImportSourceItem::STATUS_PROCESSING )->with_replaced_metadata( $metadata ) );
		$this->record_event(
			$session,
			'github.git_fetching',
			'Fetching GitHub repository files through sparse Git checkout.',
			$repo,
			array(
				'ref'  => $repo['ref'],
				'path' => $repo['source_path'],
			)
		);
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
	 * Builds the durable source item key for Git traversal state.
	 *
	 * @param array{owner:string,name:string,ref:string,source_path:string,source_url:string} $repo Repository data.
	 * @return string
	 */
	private function git_state_key( array $repo ) {
		return 'github-git:' . hash( 'sha256', strtolower( $repo['owner'] ) . '/' . strtolower( $repo['name'] ) . "\n" . $repo['ref'] . "\n" . $repo['source_path'] );
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
