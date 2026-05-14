<?php
/**
 * WP-CLI command skeleton for importer operations.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Cli;

use InvalidArgumentException;
use RuntimeException;
use UniversalImporter\Import\ImportDecision;
use UniversalImporter\Import\ImportMediaReference;
use UniversalImporter\Import\ImportProgressEvent;
use UniversalImporter\Import\ImportRelationshipMappingDecision;
use UniversalImporter\Import\ImportRunner;
use UniversalImporter\Import\ImportRunnerControls;
use UniversalImporter\Import\ImportSession;
use UniversalImporter\Import\ImportSessionId;
use UniversalImporter\Import\ImportSourceItem;
use UniversalImporter\Import\WordPressImportSessionStore;
use UniversalImporter\Plugin;

/**
 * Handles Universal WordPress Importer WP-CLI commands.
 */
final class ImportCommand {
	/**
	 * Hidden test controls reserved for failure-mode simulations.
	 *
	 * These options are intentionally absent from command docs but parsed in one
	 * place so future test workers can wire crash, timeout, and memory pressure
	 * behavior without changing the command surface.
	 */
	const TEST_ONLY_OPTIONS = array(
		'simulate-crash',
		'simulate-timeout',
		'simulate-memory-pressure',
		'simulate-post-idempotency-crash',
		'simulate-media-idempotency-crash',
		'simulate-comment-idempotency-crash',
		'simulate-fatal-exit',
		'simulate-fatal-after-markdown-cursor',
		'simulate-fatal-after-wxr-cursor',
		'simulate-fatal-after-epub-spine-cursor',
		'simulate-fatal-after-zip-entry-cursor',
		'simulate-fatal-after-rest-page-cursor',
		'simulate-fatal-after-github-tree-cursor',
		'simulate-fatal-after-post-write',
		'simulate-fatal-after-media-write',
		'simulate-fatal-after-comment-write',
		'simulate-fatal-after-pdf-structure-cursor',
		'max-ticks',
	);

	/**
	 * Persistent import session store.
	 *
	 * @var WordPressImportSessionStore|null
	 */
	private $store;

	/**
	 * Continuation scheduler callback.
	 *
	 * @var callable|null
	 */
	private $scheduler;

	/**
	 * Optional WP-CLI facade for tests.
	 *
	 * @var object|null
	 */
	private $cli;

	/**
	 * Optional runner factory for tests that need deterministic gateways.
	 *
	 * @var callable|null
	 */
	private $runner_factory;

	/**
	 * Constructor.
	 *
	 * @param WordPressImportSessionStore|null $store     Optional session store.
	 * @param callable|null                    $scheduler Optional continuation scheduler.
	 * @param object|null                      $cli       Optional WP-CLI facade.
	 * @param callable|null                    $runner_factory Optional runner factory.
	 */
	public function __construct( WordPressImportSessionStore $store = null, callable $scheduler = null, $cli = null, callable $runner_factory = null ) {
		$this->store          = $store;
		$this->scheduler      = $scheduler;
		$this->cli            = $cli;
		$this->runner_factory = $runner_factory;
	}

	/**
	 * Starts an import session for a source path or URL.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : Local path, archive path, URL, or repository URL to import.
	 *
	 * [--confirm-first-party-domains=<domains>]
	 * : Comma-separated list of source domains confirmed as first-party.
	 *
	 * [--dry-run]
	 * : Validate and plan the import without writing content.
	 *
	 * ## EXAMPLES
	 *
	 *     wp universal-importer import ./export.zip --dry-run
	 *
	 * @param array<int,string>         $args       Positional command arguments.
	 * @param array<string,string|bool> $assoc_args Associative command arguments.
	 * @return void
	 */
	public function import( $args, $assoc_args ) {
		$source = isset( $args[0] ) ? (string) $args[0] : '';

		if ( '' === $source ) {
			$this->cli_error( 'A source path or URL is required.' );
		}

		$simulation_options = $this->get_test_only_options( $assoc_args );

		if ( ! empty( $simulation_options ) ) {
			$this->cli_debug( 'Failure simulation options parsed: ' . implode( ', ', array_keys( $simulation_options ) ), 'universal-importer' );
		}

		try {
			$dry_run = ! empty( $assoc_args['dry-run'] );
			$session = ImportSession::start_for_source( $source, $dry_run );
			$store   = $this->get_store();

			$store->save( $session );
			$store->record_event(
				$session->get_id(),
				new ImportProgressEvent(
					ImportProgressEvent::LEVEL_INFO,
					'session.created',
					'Import session created and queued for continuation.',
					array(
						'source'  => $source,
						'dry_run' => $dry_run,
					)
				)
			);

			$confirmed_domains = $this->parse_confirmed_domains( isset( $assoc_args['confirm-first-party-domains'] ) ? $assoc_args['confirm-first-party-domains'] : '' );

			if ( ! empty( $confirmed_domains ) ) {
				$store->save_decision(
					$session->get_id(),
					ImportDecision::pending(
						'confirm-first-party-domains',
						'Confirm first-party domains before URL rewriting.',
						array( 'domains' => $confirmed_domains )
					)->resolve( array( 'confirmed_domains' => $confirmed_domains ) )
				);
			}

			$this->schedule_continuation( $session->get_id() );
		} catch ( InvalidArgumentException $exception ) {
			$this->cli_error( $exception->getMessage() );
			return;
		} catch ( RuntimeException $exception ) {
			$this->cli_error( $exception->getMessage() );
			return;
		}

		$this->cli_success( 'Created import session ' . $session->get_id()->to_string() . ' and scheduled continuation.' );
		$this->cli_line( 'Current status: ' . $session->get_status() );
	}

	/**
	 * Shows the current status of an import session.
	 *
	 * ## OPTIONS
	 *
	 * <session-id>
	 * : Import session identifier.
	 *
	 * @param array<int,string>         $args       Positional command arguments.
	 * @param array<string,string|bool> $assoc_args Associative command arguments.
	 * @return void
	 */
	public function status( $args, $assoc_args ) {
		unset( $assoc_args );

		$session = $this->load_session_from_args( $args );
		$this->render_session_status( $session );
	}

	/**
	 * Resumes an import session.
	 *
	 * ## OPTIONS
	 *
	 * <session-id>
	 * : Import session identifier.
	 *
	 * @param array<int,string>         $args       Positional command arguments.
	 * @param array<string,string|bool> $assoc_args Associative command arguments.
	 * @return void
	 */
	public function resume( $args, $assoc_args ) {
		unset( $assoc_args );

		try {
			$store   = $this->get_store();
			$session = $this->load_session_from_args( $args );

			if ( ImportSession::STATUS_DONE === $session->get_status() ) {
				$this->cli_error( 'Cannot resume a completed import session.' );
				return;
			}

			if ( ImportSession::STATUS_ABORTED === $session->get_status() ) {
				$this->cli_error( 'Cannot resume an aborted import session.' );
				return;
			}

			if ( ImportSession::STATUS_FAILED === $session->get_status() || ImportSession::STATUS_PAUSED === $session->get_status() ) {
				$session = $session->mark_running();
				$store->save( $session );
			}

			$store->record_event(
				$session->get_id(),
				new ImportProgressEvent(
					ImportProgressEvent::LEVEL_INFO,
					'session.resume_requested',
					'Import session was queued for another continuation tick.',
					array( 'status' => $session->get_status() )
				)
			);
			$this->schedule_continuation( $session->get_id() );
		} catch ( InvalidArgumentException $exception ) {
			$this->cli_error( $exception->getMessage() );
			return;
		} catch ( RuntimeException $exception ) {
			$this->cli_error( $exception->getMessage() );
			return;
		}

		$this->cli_success( 'Queued import session ' . $session->get_id()->to_string() . ' for continuation.' );
	}

	/**
	 * Aborts an import session.
	 *
	 * ## OPTIONS
	 *
	 * <session-id>
	 * : Import session identifier.
	 *
	 * @param array<int,string>         $args       Positional command arguments.
	 * @param array<string,string|bool> $assoc_args Associative command arguments.
	 * @return void
	 */
	public function abort( $args, $assoc_args ) {
		unset( $assoc_args );

		try {
			$store   = $this->get_store();
			$session = $this->load_session_from_args( $args );

			if ( ImportSession::STATUS_DONE === $session->get_status() ) {
				$this->cli_error( 'Cannot abort a completed import session.' );
				return;
			}

			if ( ImportSession::STATUS_ABORTED === $session->get_status() ) {
				$this->cli_warning( 'Import session is already aborted.' );
				$this->render_session_status( $session );
				return;
			}

			$session = $session->mark_aborted();
			$store->save( $session );
			$store->record_event(
				$session->get_id(),
				new ImportProgressEvent(
					ImportProgressEvent::LEVEL_WARNING,
					'session.aborted',
					'Import session was aborted by a WP-CLI operator.',
					array()
				)
			);
		} catch ( InvalidArgumentException $exception ) {
			$this->cli_error( $exception->getMessage() );
			return;
		} catch ( RuntimeException $exception ) {
			$this->cli_error( $exception->getMessage() );
			return;
		}

		$this->cli_success( 'Aborted import session ' . $session->get_id()->to_string() . '.' );
	}

	/**
	 * Resolves a pending import decision.
	 *
	 * ## OPTIONS
	 *
	 * <session-id>
	 * : Import session identifier.
	 *
	 * <decision-key>
	 * : Decision key to resolve.
	 *
	 * [--answer=<json>]
	 * : JSON object containing the structured answer.
	 *
	 * [--confirmed-domains=<domains>]
	 * : Comma-separated domains for confirm-first-party-domains.
	 *
	 * @param array<int,string>         $args       Positional command arguments.
	 * @param array<string,string|bool> $assoc_args Associative command arguments.
	 * @return void
	 */
	public function decide( $args, $assoc_args ) {
		try {
			$store        = $this->get_store();
			$session      = $this->load_session_from_args( $args );
			$decision_key = isset( $args[1] ) ? trim( (string) $args[1] ) : '';

			if ( '' === $decision_key ) {
				$this->cli_error( 'A decision key is required.' );
			}

			$decision = $store->find_decision( $session->get_id(), $decision_key );

			if ( null === $decision ) {
				$this->cli_error( 'Import decision not found: ' . $decision_key );
			}

			if ( ImportDecision::STATUS_RESOLVED === $decision->get_status() ) {
				$this->cli_warning( 'Import decision is already resolved.' );
				$this->cli_line( 'Answer: ' . $this->encode_cli_json( $decision->get_answer() ) );
				return;
			}

			$answer = $this->parse_decision_answer( $decision_key, $assoc_args );
			$store->resolve_decision( $session->get_id(), $decision_key, $answer );
			$store->record_event(
				$session->get_id(),
				new ImportProgressEvent(
					ImportProgressEvent::LEVEL_INFO,
					'decision.resolved',
					'Import decision was resolved by a WP-CLI operator.',
					array(
						'decision_key' => $decision_key,
						'answer'       => $answer,
					)
				)
			);
			$this->schedule_continuation( $session->get_id() );
		} catch ( InvalidArgumentException $exception ) {
			$this->cli_error( $exception->getMessage() );
			return;
		} catch ( RuntimeException $exception ) {
			$this->cli_error( $exception->getMessage() );
			return;
		}

		$this->cli_success( 'Resolved decision ' . $decision_key . ' for import session ' . $session->get_id()->to_string() . '.' );
	}

	/**
	 * Runs one import continuation tick.
	 *
	 * ## OPTIONS
	 *
	 * [<session-id>]
	 * : Optional import session identifier. When omitted, queued sessions are processed.
	 *
	 * [--simulate-crash]
	 * : Internal test control for a bounded runner crash after lock acquisition.
	 *
	 * [--simulate-timeout]
	 * : Internal test control for timeout-budget interruption recovery.
	 *
	 * [--simulate-memory-pressure[=<bytes>]]
	 * : Internal test control for bounded memory pressure before continuation.
	 *
	 * [--simulate-post-idempotency-crash]
	 * : Internal test control for the post-write/idempotency crash gap.
	 *
	 * [--simulate-media-idempotency-crash]
	 * : Internal test control for the media-write/idempotency crash gap.
	 *
	 * [--simulate-comment-idempotency-crash]
	 * : Internal test control for the comment-write/idempotency crash gap.
	 *
	 * [--simulate-fatal-exit]
	 * : Internal test control that exits PHP after durable lock and event writes.
	 *
	 * [--simulate-fatal-after-markdown-cursor]
	 * : Internal test control that exits PHP after a durable Markdown byte cursor write.
	 *
	 * [--simulate-fatal-after-wxr-cursor]
	 * : Internal test control that exits PHP after a durable WXR cursor write.
	 *
	 * [--simulate-fatal-after-epub-spine-cursor]
	 * : Internal test control that exits PHP after a durable EPUB spine cursor write.
	 *
	 * [--simulate-fatal-after-zip-entry-cursor]
	 * : Internal test control that exits PHP after a durable zip entry cursor write.
	 *
	 * [--simulate-fatal-after-rest-page-cursor]
	 * : Internal test control that exits PHP after a durable REST page cursor write.
	 *
	 * [--simulate-fatal-after-github-tree-cursor]
	 * : Internal test control that exits PHP after a durable GitHub tree cursor write.
	 *
	 * [--simulate-fatal-after-post-write]
	 * : Internal test control that exits PHP after a post write before idempotency is recorded.
	 *
	 * [--simulate-fatal-after-media-write]
	 * : Internal test control that exits PHP after an attachment write before idempotency is recorded.
	 *
	 * [--simulate-fatal-after-comment-write]
	 * : Internal test control that exits PHP after a comment write before idempotency is recorded.
	 *
	 * [--simulate-fatal-after-pdf-structure-cursor]
	 * : Internal test control that exits PHP after a durable PDF structure cursor write.
	 *
	 * [--max-ticks=<count>]
	 * : Internal test control limiting the number of sessions inspected in one tick.
	 *
	 * @param array<int,string>         $args       Positional command arguments.
	 * @param array<string,string|bool> $assoc_args Associative command arguments.
	 * @return void
	 */
	public function tick( $args, $assoc_args ) {
		try {
			$session_id = null;
			$controls   = ImportRunnerControls::from_cli_args( $assoc_args );

			if ( isset( $args[0] ) && '' !== (string) $args[0] ) {
				$session_id = ImportSessionId::from_string( (string) $args[0] );
			}

			$summary = $this->create_runner( $controls )->run( $session_id );
		} catch ( InvalidArgumentException $exception ) {
			$this->cli_error( $exception->getMessage() );
			return;
		} catch ( RuntimeException $exception ) {
			$this->cli_error( $exception->getMessage() );
			return;
		}

		$this->cli_success(
			sprintf(
				'Continuation tick complete: %d processed, %d locked, %d skipped, %d errors.',
				$summary['processed'],
				$summary['locked'],
				$summary['skipped'],
				$summary['errors']
			)
		);
	}

	/**
	 * Creates the runner for one CLI continuation tick.
	 *
	 * @param ImportRunnerControls $controls Hidden test controls parsed from CLI args.
	 * @return ImportRunner
	 * @throws RuntimeException When the custom runner factory returns an invalid value.
	 */
	private function create_runner( ImportRunnerControls $controls ) {
		if ( null !== $this->runner_factory ) {
			$runner = call_user_func( $this->runner_factory, $this->get_store(), $controls );

			if ( ! $runner instanceof ImportRunner ) {
				throw new RuntimeException( 'Import command runner factory must return an ImportRunner.' );
			}

			return $runner;
		}

		return new ImportRunner( $this->get_store(), 'wp-cli', null, $controls );
	}

	/**
	 * Extracts hidden failure simulation controls from WP-CLI args.
	 *
	 * @param array<string,string|bool> $assoc_args Associative command arguments.
	 * @return array<string,string|bool>
	 */
	private function get_test_only_options( array $assoc_args ) {
		$options = array();

		foreach ( self::TEST_ONLY_OPTIONS as $option_name ) {
			if ( array_key_exists( $option_name, $assoc_args ) ) {
				$options[ $option_name ] = $assoc_args[ $option_name ];
			}
		}

		return $options;
	}

	/**
	 * Loads a session store.
	 *
	 * @return WordPressImportSessionStore
	 */
	private function get_store() {
		if ( null === $this->store ) {
			$this->store = WordPressImportSessionStore::from_globals();
		}

		return $this->store;
	}

	/**
	 * Parses and validates a session id from positional args.
	 *
	 * @param array<int,string> $args Positional args.
	 * @return ImportSession
	 * @throws InvalidArgumentException When the session id is malformed or cannot be loaded.
	 */
	private function load_session_from_args( array $args ) {
		$session_id = isset( $args[0] ) ? (string) $args[0] : '';

		if ( '' === $session_id ) {
			$this->cli_error( 'A session id is required.' );
		}

		try {
			$id = ImportSessionId::from_string( $session_id );
		} catch ( InvalidArgumentException $exception ) {
			$this->cli_error( $exception->getMessage() );
			throw $exception;
		}

		$session = $this->get_store()->find( $id );

		if ( null === $session ) {
			$this->cli_error( 'Import session not found: ' . $session_id );
		}

		return $session;
	}

	/**
	 * Schedules a future continuation tick for a session.
	 *
	 * @param ImportSessionId $session_id Session id.
	 * @return void
	 * @throws RuntimeException When WordPress cron cannot be scheduled.
	 */
	private function schedule_continuation( ImportSessionId $session_id ) {
		if ( null !== $this->scheduler ) {
			$scheduler = $this->scheduler;
			$scheduler( $session_id );
			return;
		}

		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_single_event' ) ) {
			throw new RuntimeException( 'WordPress cron scheduling functions are unavailable; cannot queue import continuation.' );
		}

		$args = array( $session_id->to_string() );

		if ( false === wp_next_scheduled( Plugin::CRON_HOOK, $args ) ) {
			wp_schedule_single_event( time(), Plugin::CRON_HOOK, $args );
		}
	}

	/**
	 * Renders a human-readable session snapshot.
	 *
	 * @param ImportSession $session Session to render.
	 * @return void
	 */
	private function render_session_status( ImportSession $session ) {
		$progress  = $session->get_progress()->to_array();
		$events    = $this->get_store()->list_events( $session->get_id(), 5 );
		$decisions = $this->get_store()->list_pending_decisions( $session->get_id() );
		$warnings  = $this->get_relationship_warnings( $session );
		$backoffs  = $this->get_remote_rate_limit_summaries( $session );
		$tocs      = $this->get_epub_toc_summaries( $session );
		$pdfs      = $this->get_pdf_summaries( $session );

		$this->cli_line( 'Session: ' . $session->get_id()->to_string() );
		$this->cli_line( 'Source: ' . $session->get_source() );
		$this->cli_line( 'Status: ' . $session->get_status() );
		$this->cli_line( 'Dry run: ' . ( $session->is_dry_run() ? 'yes' : 'no' ) );
		$this->cli_line( 'Progress: ' . ( null === $progress['total'] ? '?' : (string) $progress['total'] ) . ' total, ' . $progress['completed'] . ' completed, ' . $progress['errors'] . ' errors' );

		if ( null !== $session->get_checkpoint() ) {
			$checkpoint = $session->get_checkpoint()->to_array();
			$this->cli_line( 'Checkpoint: ' . $checkpoint['cursor'] . ' after ' . $checkpoint['processed_count'] . ' processed' );
		}

		$queued_media = $this->get_store()->count_media_references_by_statuses( $session->get_id(), array( ImportMediaReference::STATUS_QUEUED ) );
		$failed_media = $this->get_store()->count_media_references_by_statuses( $session->get_id(), array( ImportMediaReference::STATUS_FAILED ) );
		$this->cli_line( 'Media references: ' . $queued_media . ' queued, ' . $failed_media . ' failed' );
		$this->cli_line( 'Imported comments: ' . $this->get_store()->count_idempotency_records_by_resource_type( $session->get_id(), 'comment' ) );

		if ( empty( $backoffs ) ) {
			$this->cli_line( 'Remote backoff: none' );
		} else {
			$this->cli_line( 'Remote backoff:' );
			foreach ( $backoffs as $backoff ) {
				$this->cli_line(
					sprintf(
						'- %s: HTTP %d, retry in %d seconds at %s',
						$backoff['source'],
						$backoff['status_code'],
						$backoff['remaining_seconds'],
						$backoff['next_retry_at']
					)
				);
				$this->cli_line( '  url: ' . $backoff['url'] );

				if ( '' !== $backoff['retry_after_header'] ) {
					$this->cli_line( '  Retry-After: ' . $backoff['retry_after_header'] );
				}
			}
		}

		if ( empty( $pdfs ) ) {
			$this->cli_line( 'PDF/OCR: none' );
		} else {
			$this->cli_line( 'PDF/OCR:' );
			foreach ( $pdfs as $pdf ) {
				$engine = '' === $pdf['ocr_status'] ? $pdf['engine'] : $pdf['engine'] . ' / ' . $pdf['ocr_status'];
				$this->cli_line( '- ' . $pdf['title'] . ': ' . $pdf['status'] . ', ' . $engine );

				if ( '' !== $pdf['message'] ) {
					$this->cli_line( '  ' . $pdf['message'] );
				}

				if ( '' !== $pdf['hint'] ) {
					$this->cli_line( '  hint: ' . $pdf['hint'] );
				}
			}
		}

		if ( empty( $tocs ) ) {
			$this->cli_line( 'EPUB TOCs: none' );
		} else {
			$this->cli_line( 'EPUB TOCs:' );
			foreach ( $tocs as $toc ) {
				$location = '' === $toc['entry'] ? '' : ' at ' . $toc['entry'];
				$this->cli_line( '- ' . $toc['title'] . ': ' . $toc['count'] . ' entries from ' . $toc['source'] . $location );

				foreach ( $toc['entries'] as $entry ) {
					$target = '' === $entry['target'] ? '' : ' -> ' . $entry['target'];
					$this->cli_line( '  - ' . $entry['label'] . $target );
				}

				if ( '' !== $toc['error'] ) {
					$this->cli_line( '  warning: ' . $toc['error'] );
				}
			}
		}

		if ( empty( $warnings ) ) {
			$this->cli_line( 'Relationship warnings: none' );
		} else {
			$this->cli_line( 'Relationship warnings:' );
			foreach ( $warnings as $warning ) {
				$this->cli_line( '- ' . $warning );
			}
		}

		if ( empty( $decisions ) ) {
			$this->cli_line( 'Pending decisions: none' );
		} else {
			$this->cli_line( 'Pending decisions:' );
			foreach ( $decisions as $decision ) {
				$this->cli_line( '- ' . $decision->get_key() . ': ' . $decision->get_prompt() );
				$options = $decision->get_options();

				if ( isset( $options['answer_template'] ) && is_array( $options['answer_template'] ) ) {
					$this->cli_line( '  answer template: ' . $this->encode_cli_json( $options['answer_template'] ) );
				}
			}
		}

		if ( empty( $events ) ) {
			$this->cli_line( 'Recent events: none' );
			return;
		}

		$this->cli_line( 'Recent events:' );
		foreach ( $events as $event ) {
			$created_at = null === $event->get_created_at() ? 'unpersisted' : $event->get_created_at();
			$this->cli_line( '- [' . $created_at . '] ' . $event->get_level() . ' ' . $event->get_type() . ': ' . $event->get_message() );
		}
	}

	/**
	 * Returns concise active remote rate-limit backoff summaries for CLI status.
	 *
	 * @param ImportSession $session Session to inspect.
	 * @return array<int,array{source:string,url:string,status_code:int,retry_after_header:string,retry_after_seconds:int,next_retry_at:string,next_retry_unix:int,remaining_seconds:int}>
	 */
	private function get_remote_rate_limit_summaries( ImportSession $session ) {
		$summaries = array();
		$items     = $this->get_store()->list_source_items_by_statuses(
			$session->get_id(),
			array(
				ImportSourceItem::STATUS_QUEUED,
				ImportSourceItem::STATUS_PROCESSING,
				ImportSourceItem::STATUS_DISCOVERED,
				ImportSourceItem::STATUS_IMPORTED,
				ImportSourceItem::STATUS_SKIPPED,
				ImportSourceItem::STATUS_FAILED,
			),
			100
		);

		foreach ( $items as $item ) {
			$metadata = $item->get_metadata();

			if ( empty( $metadata['remote_rate_limit'] ) || ! is_array( $metadata['remote_rate_limit'] ) ) {
				continue;
			}

			$summary = $this->remote_rate_limit_summary_from_metadata( $item, $metadata['remote_rate_limit'] );

			if ( null !== $summary ) {
				$summaries[] = $summary;
			}

			if ( 5 <= count( $summaries ) ) {
				break;
			}
		}

		return $summaries;
	}

	/**
	 * Builds one active remote rate-limit backoff summary from source metadata.
	 *
	 * @param ImportSourceItem    $item       Source item.
	 * @param array<string,mixed> $rate_limit Stored rate-limit metadata.
	 * @return array{source:string,url:string,status_code:int,retry_after_header:string,retry_after_seconds:int,next_retry_at:string,next_retry_unix:int,remaining_seconds:int}|null
	 */
	private function remote_rate_limit_summary_from_metadata( ImportSourceItem $item, array $rate_limit ) {
		$next_retry = isset( $rate_limit['next_retry_unix'] ) ? (int) $rate_limit['next_retry_unix'] : 0;

		if ( $next_retry <= time() ) {
			return null;
		}

		return array(
			'source'              => '' === $item->get_relative_path() ? $item->get_source_uri() : $item->get_relative_path(),
			'url'                 => isset( $rate_limit['url'] ) ? (string) $rate_limit['url'] : $item->get_source_uri(),
			'status_code'         => isset( $rate_limit['status_code'] ) ? (int) $rate_limit['status_code'] : 0,
			'retry_after_header'  => isset( $rate_limit['retry_after_header'] ) ? (string) $rate_limit['retry_after_header'] : '',
			'retry_after_seconds' => isset( $rate_limit['retry_after_seconds'] ) ? (int) $rate_limit['retry_after_seconds'] : 0,
			'next_retry_at'       => isset( $rate_limit['next_retry_at'] ) ? (string) $rate_limit['next_retry_at'] : gmdate( 'c', $next_retry ),
			'next_retry_unix'     => $next_retry,
			'remaining_seconds'   => max( 0, $next_retry - time() ),
		);
	}

	/**
	 * Returns concise PDF/OCR summaries for CLI status output.
	 *
	 * @param ImportSession $session Session to inspect.
	 * @return array<int,array{title:string,status:string,engine:string,ocr_status:string,message:string,hint:string}>
	 */
	private function get_pdf_summaries( ImportSession $session ) {
		$summaries = array();
		$items     = $this->get_store()->list_source_items_by_statuses(
			$session->get_id(),
			array(
				ImportSourceItem::STATUS_IMPORTED,
				ImportSourceItem::STATUS_FAILED,
				ImportSourceItem::STATUS_DISCOVERED,
			),
			100
		);

		foreach ( $items as $item ) {
			$metadata = $item->get_metadata();

			if ( 'pdf' !== ( isset( $metadata['document_format'] ) ? (string) $metadata['document_format'] : '' ) ) {
				continue;
			}

			$summaries[] = $this->pdf_summary_from_metadata( $item, $metadata );

			if ( 5 <= count( $summaries ) ) {
				break;
			}
		}

		return $summaries;
	}

	/**
	 * Builds one PDF/OCR summary from source item metadata.
	 *
	 * @param ImportSourceItem    $item     Source item.
	 * @param array<string,mixed> $metadata Source item metadata.
	 * @return array{title:string,status:string,engine:string,ocr_status:string,message:string,hint:string}
	 */
	private function pdf_summary_from_metadata( ImportSourceItem $item, array $metadata ) {
		$title = isset( $metadata['title'] ) && '' !== trim( (string) $metadata['title'] )
			? trim( (string) $metadata['title'] )
			: ( '' === $item->get_relative_path() ? $item->get_source_uri() : $item->get_relative_path() );
		$hints = array();

		if ( isset( $metadata['pdf_external_text_hint'] ) && '' !== trim( (string) $metadata['pdf_external_text_hint'] ) ) {
			$hints[] = trim( (string) $metadata['pdf_external_text_hint'] );
		}

		if ( isset( $metadata['pdf_ocr_hint'] ) && '' !== trim( (string) $metadata['pdf_ocr_hint'] ) ) {
			$hints[] = trim( (string) $metadata['pdf_ocr_hint'] );
		}

		if ( isset( $metadata['pdf_layout_warning'] ) && '' !== trim( (string) $metadata['pdf_layout_warning'] ) ) {
			$hints[] = trim( (string) $metadata['pdf_layout_warning'] );
		}

		if ( isset( $metadata['pdf_embedded_media_hint'] ) && '' !== trim( (string) $metadata['pdf_embedded_media_hint'] ) ) {
			$hints[] = trim( (string) $metadata['pdf_embedded_media_hint'] );
		}

		if ( isset( $metadata['pdf_structure_warning'] ) && '' !== trim( (string) $metadata['pdf_structure_warning'] ) ) {
			$hints[] = trim( (string) $metadata['pdf_structure_warning'] );
		}

		return array(
			'title'      => $title,
			'status'     => $item->get_status(),
			'engine'     => isset( $metadata['pdf_text_engine'] ) && '' !== (string) $metadata['pdf_text_engine'] ? (string) $metadata['pdf_text_engine'] : 'unknown',
			'ocr_status' => isset( $metadata['pdf_ocr_status'] ) ? (string) $metadata['pdf_ocr_status'] : '',
			'message'    => isset( $metadata['error'] ) ? (string) $metadata['error'] : '',
			'hint'       => implode( ' ', $hints ),
		);
	}

	/**
	 * Returns recent relationship warning summaries for CLI status output.
	 *
	 * @param ImportSession $session Session to inspect.
	 * @return array<int,string>
	 */
	private function get_relationship_warnings( ImportSession $session ) {
		$warnings = array();

		foreach ( $this->get_store()->list_events( $session->get_id(), 50 ) as $event ) {
			if ( ImportRelationshipMappingDecision::WARNING_EVENT !== $event->get_type() ) {
				continue;
			}

			$warnings[] = $this->summarize_relationship_warning( $event );

			if ( 5 <= count( $warnings ) ) {
				break;
			}
		}

		return $warnings;
	}

	/**
	 * Returns concise EPUB table-of-contents summaries for CLI status output.
	 *
	 * @param ImportSession $session Session to inspect.
	 * @return array<int,array{title:string,source:string,entry:string,count:int,error:string,entries:array<int,array{label:string,target:string}>}>
	 */
	private function get_epub_toc_summaries( ImportSession $session ) {
		$summaries = array();
		$items     = $this->get_store()->list_source_items_by_statuses(
			$session->get_id(),
			array(
				ImportSourceItem::STATUS_DISCOVERED,
				ImportSourceItem::STATUS_IMPORTED,
				ImportSourceItem::STATUS_FAILED,
			),
			100
		);

		foreach ( $items as $item ) {
			$metadata = $item->get_metadata();

			if ( empty( $metadata['epub_toc_count'] ) && empty( $metadata['epub_toc_error'] ) ) {
				continue;
			}

			$summaries[] = $this->epub_toc_summary_from_metadata( $item, $metadata );

			if ( 5 <= count( $summaries ) ) {
				break;
			}
		}

		return $summaries;
	}

	/**
	 * Builds one EPUB TOC summary from source item metadata.
	 *
	 * @param ImportSourceItem    $item     Source item.
	 * @param array<string,mixed> $metadata Source item metadata.
	 * @return array{title:string,source:string,entry:string,count:int,error:string,entries:array<int,array{label:string,target:string}>}
	 */
	private function epub_toc_summary_from_metadata( ImportSourceItem $item, array $metadata ) {
		$title = isset( $metadata['epub_title'] ) && '' !== trim( (string) $metadata['epub_title'] )
			? trim( (string) $metadata['epub_title'] )
			: ( '' === $item->get_relative_path() ? $item->get_source_uri() : $item->get_relative_path() );

		return array(
			'title'   => $title,
			'source'  => isset( $metadata['epub_toc_source'] ) && '' !== (string) $metadata['epub_toc_source'] ? (string) $metadata['epub_toc_source'] : 'unknown',
			'entry'   => isset( $metadata['epub_toc_entry'] ) ? (string) $metadata['epub_toc_entry'] : '',
			'count'   => isset( $metadata['epub_toc_count'] ) ? (int) $metadata['epub_toc_count'] : 0,
			'error'   => isset( $metadata['epub_toc_error'] ) ? (string) $metadata['epub_toc_error'] : '',
			'entries' => $this->normalize_epub_toc_entries( isset( $metadata['epub_toc_entries'] ) && is_array( $metadata['epub_toc_entries'] ) ? $metadata['epub_toc_entries'] : array() ),
		);
	}

	/**
	 * Normalizes a bounded sample of EPUB TOC entries for display.
	 *
	 * @param array<int,array<string,mixed>> $entries Raw TOC entries.
	 * @return array<int,array{label:string,target:string}>
	 */
	private function normalize_epub_toc_entries( array $entries ) {
		$normalized = array();

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['label'] ) ) {
				continue;
			}

			$target = '';
			if ( ! empty( $entry['target_path'] ) ) {
				$target = (string) $entry['target_path'];
			}
			if ( ! empty( $entry['target_fragment'] ) ) {
				$target .= '#' . (string) $entry['target_fragment'];
			}

			$normalized[] = array(
				'label'  => (string) $entry['label'],
				'target' => $target,
			);

			if ( 3 <= count( $normalized ) ) {
				break;
			}
		}

		return $normalized;
	}

	/**
	 * Builds a concise human-readable relationship warning summary.
	 *
	 * @param ImportProgressEvent $event Warning event.
	 * @return string
	 */
	private function summarize_relationship_warning( ImportProgressEvent $event ) {
		return ImportRelationshipMappingDecision::summarize_warning_event( $event );
	}

	/**
	 * Parses confirmed first-party domain option values.
	 *
	 * @param string|bool $value Raw option value.
	 * @return array<int,string>
	 */
	private function parse_confirmed_domains( $value ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return array();
		}

		$domains = array();

		foreach ( explode( ',', $value ) as $domain ) {
			$domain = strtolower( trim( $domain ) );

			if ( '' !== $domain && ! in_array( $domain, $domains, true ) ) {
				$domains[] = $domain;
			}
		}

		return $domains;
	}

	/**
	 * Parses the answer for a decision command.
	 *
	 * @param string                    $decision_key Decision key.
	 * @param array<string,string|bool> $assoc_args   Associative command arguments.
	 * @return array<string,mixed>
	 * @throws InvalidArgumentException When the answer is missing or malformed.
	 */
	private function parse_decision_answer( $decision_key, array $assoc_args ) {
		if ( 'confirm-first-party-domains' === $decision_key && array_key_exists( 'confirmed-domains', $assoc_args ) ) {
			$domains = $this->parse_confirmed_domains( $assoc_args['confirmed-domains'] );

			if ( empty( $domains ) ) {
				throw new InvalidArgumentException( 'At least one confirmed domain is required.' );
			}

			return array( 'confirmed_domains' => $domains );
		}

		if ( ! array_key_exists( 'answer', $assoc_args ) || ! is_string( $assoc_args['answer'] ) || '' === trim( $assoc_args['answer'] ) ) {
			throw new InvalidArgumentException( 'A JSON object answer is required. Use --answer=\'{"key":"value"}\'.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_decode_json_decode -- CLI accepts a portable JSON object.
		$answer = json_decode( $assoc_args['answer'], true );

		if ( ! is_array( $answer ) || JSON_ERROR_NONE !== json_last_error() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
			throw new InvalidArgumentException( 'Decision answer must be a valid JSON object: ' . json_last_error_msg() );
		}

		return $answer;
	}

	/**
	 * Encodes a CLI diagnostic JSON object.
	 *
	 * @param array<string,mixed>|null $data Data to encode.
	 * @return string
	 */
	private function encode_cli_json( array $data = null ) {
		if ( null === $data ) {
			return '{}';
		}

		if ( function_exists( 'wp_json_encode' ) ) {
			$json = wp_json_encode( $data );
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Unit tests run without WordPress.
			$json = json_encode( $data );
		}

		return is_string( $json ) ? $json : '{}';
	}

	/**
	 * Emits a WP-CLI success message.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private function cli_success( $message ) {
		if ( null !== $this->cli ) {
			$this->cli->success( $message );
			return;
		}

		\WP_CLI::success( $message );
	}

	/**
	 * Emits a WP-CLI warning message.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private function cli_warning( $message ) {
		if ( null !== $this->cli ) {
			$this->cli->warning( $message );
			return;
		}

		\WP_CLI::warning( $message );
	}

	/**
	 * Emits a WP-CLI line.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private function cli_line( $message ) {
		if ( null !== $this->cli ) {
			$this->cli->line( $message );
			return;
		}

		\WP_CLI::line( $message );
	}

	/**
	 * Emits a WP-CLI debug line.
	 *
	 * @param string $message Message.
	 * @param string $group   Debug group.
	 * @return void
	 */
	private function cli_debug( $message, $group ) {
		if ( null !== $this->cli ) {
			$this->cli->debug( $message, $group );
			return;
		}

		\WP_CLI::debug( $message, $group );
	}

	/**
	 * Emits a WP-CLI error.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private function cli_error( $message ) {
		if ( null !== $this->cli ) {
			$this->cli->error( $message );
			return;
		}

		\WP_CLI::error( $message );
	}
}
