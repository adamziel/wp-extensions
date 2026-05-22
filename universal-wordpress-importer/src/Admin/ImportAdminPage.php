<?php
/**
 * WordPress admin surface for browser-driven imports.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Admin;

use InvalidArgumentException;
use RuntimeException;
use UniversalImporter\Import\GitHubRepositorySourceUrl;
use UniversalImporter\Import\ImportCacheDirectory;
use UniversalImporter\Import\ImportDecision;
use UniversalImporter\Import\ImportMediaReference;
use UniversalImporter\Import\ImportPreparedDocument;
use UniversalImporter\Import\ImportPostPersister;
use UniversalImporter\Import\ImportProgressEvent;
use UniversalImporter\Import\ImportRemoteContentFetcherInterface;
use UniversalImporter\Import\ImportRelationshipMappingDecision;
use UniversalImporter\Import\ImportRunner;
use UniversalImporter\Import\ImportSession;
use UniversalImporter\Import\ImportSessionId;
use UniversalImporter\Import\ImportSourceItem;
use UniversalImporter\Import\WordPressRemoteContentFetcher;
use UniversalImporter\Import\WordPressImportSessionStore;
use UniversalImporter\Plugin;

/**
 * Registers and serves the importer admin page and keepalive endpoints.
 */
final class ImportAdminPage {
	const PAGE_SLUG             = 'universal-wordpress-importer';
	const NONCE_ACTION          = 'universal_importer_admin';
	const AJAX_CREATE           = 'universal_importer_create_session';
	const AJAX_UPLOAD           = 'universal_importer_upload_session';
	const AJAX_KEEPALIVE        = 'universal_importer_keepalive';
	const AJAX_ABORT            = 'universal_importer_abort_session';
	const AJAX_DECIDE           = 'universal_importer_resolve_decision';
	const AJAX_GITHUB_DIRS      = 'universal_importer_github_directories';
	const CAPABILITY            = 'manage_options';
	const RECENT_SESSIONS       = 10;
	const MAX_UPLOAD_FILES      = 500;
	const MAX_UPLOAD_BYTES      = 134217728;
	const KEEPALIVE_BURST_TICKS = 4;

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
	 * Runner factory callback.
	 *
	 * @var callable|null
	 */
	private $runner_factory;

	/**
	 * Optional upload cache directory override.
	 *
	 * @var ImportCacheDirectory|null
	 */
	private $cache_directory;

	/**
	 * Optional remote content fetcher override.
	 *
	 * @var ImportRemoteContentFetcherInterface|null
	 */
	private $content_fetcher;

	/**
	 * Constructor.
	 *
	 * @param WordPressImportSessionStore|null         $store          Optional session store.
	 * @param callable|null                            $scheduler      Optional continuation scheduler.
	 * @param callable|null                            $runner_factory Optional runner factory.
	 * @param ImportCacheDirectory|null                $cache_directory Optional upload cache directory.
	 * @param ImportRemoteContentFetcherInterface|null $content_fetcher Optional GitHub directory fetcher.
	 */
	public function __construct( WordPressImportSessionStore $store = null, callable $scheduler = null, callable $runner_factory = null, ImportCacheDirectory $cache_directory = null, ImportRemoteContentFetcherInterface $content_fetcher = null ) {
		$this->store           = $store;
		$this->scheduler       = $scheduler;
		$this->runner_factory  = $runner_factory;
		$this->cache_directory = $cache_directory;
		$this->content_fetcher = $content_fetcher;
	}

	/**
	 * Builds the admin page from WordPress globals.
	 *
	 * @return self
	 */
	public static function from_globals() {
		return new self( WordPressImportSessionStore::from_globals() );
	}

	/**
	 * Registers WordPress admin hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'pre_get_posts', array( $this, 'filter_imported_content_query' ) );
		add_action( 'wp_ajax_' . self::AJAX_CREATE, array( $this, 'ajax_create_session' ) );
		add_action( 'wp_ajax_' . self::AJAX_UPLOAD, array( $this, 'ajax_upload_session' ) );
		add_action( 'wp_ajax_' . self::AJAX_KEEPALIVE, array( $this, 'ajax_keepalive' ) );
		add_action( 'wp_ajax_' . self::AJAX_ABORT, array( $this, 'ajax_abort_session' ) );
		add_action( 'wp_ajax_' . self::AJAX_DECIDE, array( $this, 'ajax_resolve_decision' ) );
		add_action( 'wp_ajax_' . self::AJAX_GITHUB_DIRS, array( $this, 'ajax_github_directories' ) );
	}

	/**
	 * Registers the Tools page entry.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_management_page(
			__( 'Universal Importer', 'universal-wordpress-importer' ),
			__( 'Universal Importer', 'universal-wordpress-importer' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Filters the Pages list to content imported by one session.
	 *
	 * @param mixed $query WordPress query object.
	 * @return void
	 */
	public function filter_imported_content_query( $query ) {
		if ( ! function_exists( 'is_admin' ) || ! is_admin() || ! is_object( $query ) || ! method_exists( $query, 'is_main_query' ) || ! $query->is_main_query() ) {
			return;
		}

		$raw = filter_input( INPUT_GET, 'universal_importer_session_id', FILTER_UNSAFE_RAW );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return;
		}

		if ( method_exists( $query, 'get' ) && 'page' !== (string) $query->get( 'post_type' ) ) {
			return;
		}

		$raw = function_exists( 'wp_unslash' ) ? wp_unslash( $raw ) : $raw;
		$raw = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $raw ) : trim( (string) $raw );

		try {
			$session_id = ImportSessionId::from_string( $raw );
		} catch ( InvalidArgumentException $exception ) {
			return;
		}

		$meta_query = method_exists( $query, 'get' ) ? $query->get( 'meta_query' ) : array();
		if ( ! is_array( $meta_query ) ) {
			$meta_query = array();
		}

		$meta_query[] = array(
			'key'   => '_universal_importer_session_id',
			'value' => $session_id->to_string(),
		);

		if ( method_exists( $query, 'set' ) ) {
			$query->set( 'post_type', 'page' );
			$query->set( 'meta_query', $meta_query );
		}
	}

	/**
	 * Creates and queues an import session from admin input.
	 *
	 * @param string            $source            Source path or URL.
	 * @param array<int,string> $confirmed_domains Confirmed first-party domains.
	 * @param bool              $dry_run           Whether this is a dry run.
	 * @param string            $url_rewrite_mode  URL rewrite mode: ask, rewrite, or preserve.
	 * @param bool              $import_as_drafts  Whether imported posts should remain drafts.
	 * @return array<string,mixed>
	 * @throws InvalidArgumentException When source input is invalid.
	 */
	public function create_import_session( $source, array $confirmed_domains = array(), $dry_run = false, $url_rewrite_mode = 'ask', $import_as_drafts = false ) {
		$source = trim( (string) $source );

		if ( '' === $source ) {
			throw new InvalidArgumentException( 'A source path or URL is required.' );
		}

		$store   = $this->get_store();
		$dry_run = (bool) $dry_run;
		$session = ImportSession::start_for_source( $source, $dry_run );

		$store->save( $session );
		$store->record_event(
			$session->get_id(),
			new ImportProgressEvent(
				ImportProgressEvent::LEVEL_INFO,
				'session.created',
				'Import session created from the WordPress admin page.',
				array(
					'source'  => $source,
					'dry_run' => $dry_run,
				)
			)
		);
		$this->record_initial_github_queue_event( $session );

		$this->save_initial_url_rewrite_preference( $session->get_id(), $confirmed_domains, $url_rewrite_mode );
		$this->save_initial_post_status_preference( $session->get_id(), (bool) $import_as_drafts );

		$this->schedule_continuation( $session->get_id() );

		return $this->get_status_snapshot( $session->get_id() );
	}

	/**
	 * Stages browser-uploaded files into importer cache and queues a directory import.
	 *
	 * @param array<int,array<string,mixed>> $files             Uploaded file rows.
	 * @param array<int,string>              $relative_paths    Browser-provided relative paths.
	 * @param array<int,string>              $confirmed_domains Confirmed first-party domains.
	 * @param bool                           $dry_run           Whether this is a dry run.
	 * @param string                         $url_rewrite_mode  URL rewrite mode: ask, rewrite, or preserve.
	 * @param bool                           $import_as_drafts  Whether imported posts should remain drafts.
	 * @return array<string,mixed>
	 * @throws InvalidArgumentException When upload input is invalid or staging fails.
	 */
	public function create_import_session_from_uploaded_files( array $files, array $relative_paths = array(), array $confirmed_domains = array(), $dry_run = false, $url_rewrite_mode = 'ask', $import_as_drafts = false ) {
		if ( empty( $files ) ) {
			throw new InvalidArgumentException( 'At least one uploaded file is required.' );
		}

		if ( self::MAX_UPLOAD_FILES < count( $files ) ) {
			throw new InvalidArgumentException( 'Too many files were submitted in one browser import.' );
		}

		$session_id = ImportSessionId::generate();
		$root       = $this->get_cache_directory()->path_for( $session_id, 'browser-uploads', array( 'tree' ) );
		$total      = 0;
		$seen       = array();

		foreach ( array_values( $files ) as $index => $file ) {
			$error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_OK;

			if ( UPLOAD_ERR_OK !== $error ) {
				throw new InvalidArgumentException( 'A browser-uploaded file could not be read; PHP reported an upload error.' );
			}

			$tmp_name = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
			$name     = isset( $file['name'] ) ? (string) $file['name'] : '';
			$size     = isset( $file['size'] ) ? (int) $file['size'] : ( is_file( $tmp_name ) ? (int) filesize( $tmp_name ) : 0 );

			$total += max( 0, $size );

			if ( self::MAX_UPLOAD_BYTES < $total ) {
				throw new InvalidArgumentException( 'Browser upload import is limited to 128 MB per session.' );
			}

			$relative_path = isset( $relative_paths[ $index ] ) && '' !== trim( (string) $relative_paths[ $index ] )
				? (string) $relative_paths[ $index ]
				: $name;
			$segments      = $this->normalize_uploaded_relative_path( $relative_path );
			$target        = $root . DIRECTORY_SEPARATOR . implode( DIRECTORY_SEPARATOR, $segments );
			$target_key    = implode( '/', $segments );

			if ( isset( $seen[ $target_key ] ) ) {
				throw new InvalidArgumentException( 'Browser upload contains a duplicate file path.' );
			}

			$seen[ $target_key ] = true;
			$this->stage_uploaded_file( $tmp_name, $target );
		}

		$session           = ImportSession::start_with_id_for_source( $session_id, $root, (bool) $dry_run );
		$store             = $this->get_store();
		$confirmed_domains = $this->normalize_domain_list( $confirmed_domains );

		$store->save( $session );
		$store->record_event(
			$session->get_id(),
			new ImportProgressEvent(
				ImportProgressEvent::LEVEL_INFO,
				'session.created',
				'Import session created from browser-uploaded files.',
				array(
					'source'       => $root,
					'dry_run'      => (bool) $dry_run,
					'upload_files' => count( $files ),
					'upload_bytes' => $total,
				)
			)
		);

		$this->save_initial_url_rewrite_preference( $session->get_id(), $confirmed_domains, $url_rewrite_mode );
		$this->save_initial_post_status_preference( $session->get_id(), (bool) $import_as_drafts );

		$this->schedule_continuation( $session->get_id() );

		return $this->get_status_snapshot( $session->get_id() );
	}

	/**
	 * Lists repository directories available for optional GitHub subtree selection.
	 *
	 * @param string $source GitHub repository URL.
	 * @return array<string,mixed>
	 * @throws InvalidArgumentException When the URL is not a supported GitHub repository URL.
	 * @throws RuntimeException When GitHub directory loading fails.
	 */
	public function list_github_directories( $source ) {
		$repo = GitHubRepositorySourceUrl::parse( $source );

		if ( null === $repo ) {
			throw new InvalidArgumentException( 'Enter a GitHub repository URL to browse directories.' );
		}

		$fetcher = $this->get_content_fetcher();

		if ( 'HEAD' === strtoupper( $repo['ref'] ) ) {
			$repo['ref'] = $this->fetch_github_default_branch( $fetcher, $repo );
		}

		$last_exception = null;

		foreach ( GitHubRepositorySourceUrl::candidates( $repo ) as $candidate ) {
			try {
				$tree = $fetcher->fetch_json( GitHubRepositorySourceUrl::tree_api_url( $candidate ) );

				if ( ! is_array( $tree ) || ! isset( $tree['tree'] ) || ! is_array( $tree['tree'] ) ) {
					throw new RuntimeException( 'GitHub tree response was malformed.' );
				}

				return $this->github_directory_snapshot( $candidate, $tree );
			} catch ( RuntimeException $exception ) {
				$last_exception = $exception;
			}
		}

		$message = null === $last_exception ? 'GitHub directory tree could not be loaded.' : $last_exception->getMessage();

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are returned through escaped AJAX JSON.
		throw new RuntimeException( 'GitHub directory tree could not be loaded: ' . $message );
	}

	/**
	 * Runs one browser keepalive tick and returns a fresh snapshot.
	 *
	 * @param string|null $session_id Optional session id.
	 * @return array<string,mixed>
	 * @throws InvalidArgumentException When the session id is invalid.
	 * @throws RuntimeException When the runner or snapshot load fails.
	 */
	public function run_keepalive( $session_id = null ) {
		$id = null;

		if ( null !== $session_id && '' !== trim( (string) $session_id ) ) {
			$id = ImportSessionId::from_string( (string) $session_id );
		}

		$runner   = $this->create_runner();
		$summary  = $runner->run( $id );
		$snapshot = null === $id ? null : $this->get_status_snapshot( $id );

		$should_burst = null !== $id && $this->should_burst_keepalive( $snapshot, $summary );
		for ( $tick = 1; $tick < self::KEEPALIVE_BURST_TICKS && $should_burst; ++$tick ) {
			$next_summary = $runner->run( $id );
			$summary      = $this->combine_keepalive_summaries( $summary, $next_summary );
			$snapshot     = $this->get_status_snapshot( $id );
			$should_burst = $this->should_burst_keepalive( $snapshot, $summary );
		}

		return array(
			'summary' => $summary,
			'session' => $snapshot,
		);
	}

	/**
	 * Returns whether one AJAX keepalive may safely run another bounded tick.
	 *
	 * @param array<string,mixed>|null $snapshot Current session snapshot.
	 * @param array<string,int>        $summary  Previous runner summary.
	 * @return bool
	 */
	private function should_burst_keepalive( $snapshot, array $summary ) {
		if ( null === $snapshot || ! empty( $summary['locked'] ) || ! empty( $summary['errors'] ) ) {
			return false;
		}

		if ( empty( $snapshot['dashboard'] ) || empty( $snapshot['dashboard']['needs_keepalive'] ) ) {
			return false;
		}

		$source_items = isset( $snapshot['source_items'] ) && is_array( $snapshot['source_items'] ) ? $snapshot['source_items'] : array();
		$media        = isset( $snapshot['media'] ) && is_array( $snapshot['media'] ) ? $snapshot['media'] : array();
		$source_total = isset( $source_items['total'] ) ? (int) $source_items['total'] : 0;
		$media_total  = isset( $media['total'] ) ? (int) $media['total'] : 0;
		$documents    = isset( $snapshot['prepared_documents']['total'] ) ? (int) $snapshot['prepared_documents']['total'] : 0;
		$posts        = isset( $snapshot['posts']['persisted'] ) ? (int) $snapshot['posts']['persisted'] : 0;

		return 0 < $source_total || 0 < $media_total || $posts < $documents;
	}

	/**
	 * Adds runner summary counters.
	 *
	 * @param array<string,int> $summary Existing summary.
	 * @param array<string,int> $next    Next summary.
	 * @return array<string,int>
	 */
	private function combine_keepalive_summaries( array $summary, array $next ) {
		foreach ( array( 'processed', 'locked', 'skipped', 'errors' ) as $key ) {
			$summary[ $key ] = ( isset( $summary[ $key ] ) ? (int) $summary[ $key ] : 0 ) + ( isset( $next[ $key ] ) ? (int) $next[ $key ] : 0 );
		}

		return $summary;
	}

	/**
	 * Aborts an import session from the admin page.
	 *
	 * @param string $session_id Session id.
	 * @return array<string,mixed>
	 * @throws RuntimeException When the session cannot be aborted.
	 */
	public function abort_import_session( $session_id ) {
		$id      = ImportSessionId::from_string( (string) $session_id );
		$store   = $this->get_store();
		$session = $store->find( $id );

		if ( null === $session ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are sent through escaped admin/AJAX renderers.
			throw new RuntimeException( 'Import session not found: ' . $id->to_string() );
		}

		if ( ImportSession::STATUS_DONE === $session->get_status() ) {
			throw new RuntimeException( 'Cannot abort a completed import session.' );
		}

		if ( ImportSession::STATUS_ABORTED !== $session->get_status() ) {
			$session = $session->mark_aborted();
			$store->save( $session );
			$store->record_event(
				$session->get_id(),
				new ImportProgressEvent(
					ImportProgressEvent::LEVEL_WARNING,
					'session.aborted',
					'Import session was aborted from the WordPress admin page.',
					array()
				)
			);
		}

		return $this->get_status_snapshot( $id );
	}

	/**
	 * Resolves a pending import decision from the admin page.
	 *
	 * @param string              $session_id   Session id.
	 * @param string              $decision_key Decision key.
	 * @param array<string,mixed> $answer       Structured answer.
	 * @return array<string,mixed>
	 * @throws InvalidArgumentException When the decision key or answer is invalid.
	 * @throws RuntimeException When the session or decision cannot be loaded.
	 */
	public function resolve_import_decision( $session_id, $decision_key, array $answer ) {
		$id           = ImportSessionId::from_string( (string) $session_id );
		$decision_key = trim( (string) $decision_key );

		if ( '' === $decision_key ) {
			throw new InvalidArgumentException( 'A decision key is required.' );
		}

		$store   = $this->get_store();
		$session = $store->find( $id );

		if ( null === $session ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are sent through escaped admin/AJAX renderers.
			throw new RuntimeException( 'Import session not found: ' . $id->to_string() );
		}

		$decision = $store->find_decision( $id, $decision_key );

		if ( null === $decision ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are sent through escaped admin/AJAX renderers.
			throw new RuntimeException( 'Import decision not found: ' . $decision_key );
		}

		if ( ImportDecision::STATUS_PENDING === $decision->get_status() ) {
			$store->resolve_decision( $id, $decision_key, $answer );
			$store->record_event(
				$id,
				new ImportProgressEvent(
					ImportProgressEvent::LEVEL_INFO,
					'decision.resolved',
					'Import decision was resolved from the WordPress admin page.',
					array(
						'decision_key' => $decision_key,
						'answer'       => $answer,
					)
				)
			);
			$this->schedule_continuation( $id );
		}

		return $this->get_status_snapshot( $id );
	}

	/**
	 * Returns an admin-friendly session snapshot.
	 *
	 * @param ImportSessionId $id Session id.
	 * @return array<string,mixed>
	 * @throws RuntimeException When the session cannot be loaded.
	 */
	public function get_status_snapshot( ImportSessionId $id ) {
		$store   = $this->get_store();
		$session = $store->find( $id );

		if ( null === $session ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are sent through escaped admin/AJAX renderers.
			throw new RuntimeException( 'Import session not found: ' . $id->to_string() );
		}

		$checkpoint         = $session->get_checkpoint();
		$source_id          = $session->get_id();
		$source_items       = $this->get_source_item_snapshot( $source_id );
		$prepared_documents = array(
			'total'  => $store->count_prepared_documents( $source_id ),
			'recent' => array_map(
				array( $this, 'prepared_document_to_snapshot' ),
				$store->list_recent_prepared_documents( $source_id, 5 )
			),
		);
		$posts              = array(
			'persisted' => $store->count_idempotency_records_by_resource_type( $source_id, 'post' ),
		);
		$post_status        = $this->post_status_for_snapshot( $source_id );
		$comments           = array(
			'persisted' => $store->count_idempotency_records_by_resource_type( $source_id, 'comment' ),
		);
		$media              = $this->get_media_reference_snapshot( $source_id );
		$remote_backoff     = $this->get_remote_rate_limit_snapshot( $source_id );
		$github_git         = $this->get_github_git_snapshot( $source_id );
		$pdf_documents      = $this->get_pdf_document_snapshot( $source_id );
		$epub_tocs          = $this->get_epub_toc_snapshot( $source_id );
		$warnings           = $this->get_relationship_warning_snapshot( $source_id );
		$pending_decisions  = array_map(
			array( $this, 'decision_to_snapshot' ),
			$store->list_pending_decisions( $session->get_id() )
		);
		$recent_events      = array_map(
			array( $this, 'event_to_snapshot' ),
			$store->list_events( $session->get_id(), 14 )
		);
		$snapshot           = array(
			'id'                    => $session->get_id()->to_string(),
			'source'                => $session->get_source(),
			'status'                => $session->get_status(),
			'dry_run'               => $session->is_dry_run(),
			'progress'              => $session->get_progress()->to_array(),
			'checkpoint'            => null === $checkpoint ? null : $checkpoint->to_array(),
			'source_items'          => $source_items,
			'prepared_documents'    => $prepared_documents,
			'posts'                 => $posts,
			'post_status'           => $post_status,
			'comments'              => $comments,
			'media'                 => $media,
			'remote_backoff'        => $remote_backoff,
			'github_git'            => $github_git,
			'pdf_documents'         => $pdf_documents,
			'epub_tocs'             => $epub_tocs,
			'relationship_warnings' => $warnings,
			'pending_decisions'     => $pending_decisions,
			'recent_events'         => $recent_events,
		);

		$snapshot['dashboard']                         = $this->build_dashboard_snapshot( $snapshot );
		$snapshot['dashboard']['imported_content_url'] = $this->imported_content_url( $source_id, $posts );

		return $snapshot;
	}

	/**
	 * Returns the selected post status for admin display.
	 *
	 * @param ImportSessionId $session_id Session id.
	 * @return string
	 */
	private function post_status_for_snapshot( ImportSessionId $session_id ) {
		$decision = $this->get_store()->find_decision( $session_id, ImportPostPersister::POST_STATUS_DECISION_KEY );

		if ( null === $decision || ImportDecision::STATUS_RESOLVED !== $decision->get_status() ) {
			return 'publish';
		}

		$answer = $decision->get_answer();

		return isset( $answer['post_status'] ) && 'draft' === (string) $answer['post_status'] ? 'draft' : 'publish';
	}

	/**
	 * Builds the admin URL for viewing imported pages from one session.
	 *
	 * @param ImportSessionId     $session_id Session id.
	 * @param array<string,mixed> $posts      Post snapshot.
	 * @return string
	 */
	private function imported_content_url( ImportSessionId $session_id, array $posts ) {
		if ( empty( $posts['persisted'] ) || ! function_exists( 'admin_url' ) ) {
			return '';
		}

		$query = array(
			'post_type'                     => 'page',
			'universal_importer_session_id' => $session_id->to_string(),
		);

		if ( function_exists( 'add_query_arg' ) ) {
			return (string) add_query_arg( $query, admin_url( 'edit.php' ) );
		}

		return admin_url( 'edit.php?post_type=page&universal_importer_session_id=' . rawurlencode( $session_id->to_string() ) );
	}

	/**
	 * Lists recent session snapshots for the admin page.
	 *
	 * @param int $limit Maximum number of sessions.
	 * @return array<int,array<string,mixed>>
	 */
	public function list_recent_session_snapshots( $limit = self::RECENT_SESSIONS ) {
		return array_map(
			function ( ImportSession $session ) {
				return $this->get_status_snapshot( $session->get_id() );
			},
			$this->get_store()->list_recent_sessions( $limit )
		);
	}

	/**
	 * Renders the admin page.
	 *
	 * @return void
	 */
	public function render_admin_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage imports.', 'universal-wordpress-importer' ) );
		}

		$nonce    = wp_create_nonce( self::NONCE_ACTION );
		$sessions = array();
		$error    = '';

		try {
			$sessions = $this->list_recent_session_snapshots();
		} catch ( RuntimeException $exception ) {
			$error = $exception->getMessage();
		}

		$primary_session   = $this->primary_admin_session( $sessions );
		$has_active_import = null !== $primary_session && $this->is_active_admin_session( $primary_session );
		$config            = array(
			'nonce'              => $nonce,
			'sessions'           => null === $primary_session ? array() : array( $primary_session ),
			'primary_session_id' => null === $primary_session ? '' : (string) $primary_session['id'],
		);

		?>
		<style>
			.universal-importer-admin {
				--ui-bg: #fbf8f1;
				--ui-ink: #1f2937;
				--ui-rule: #eadfca;
				--ui-rule2: #d9caa3;
				--ui-accent: #a16207;
				--ui-soft: #fef3c7;
				--ui-muted: #7a6a52;
				--ui-ok: #365314;
				--ui-warn: #92400e;
				--ui-warn-bg: #fff3df;
				background: var(--ui-bg);
				color: var(--ui-ink);
				font: 14.5px/1.55 "Inter", ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
				margin: 0 auto;
				max-width: 720px;
				padding: 24px 22px 80px;
			}
			.universal-importer-admin,
			.universal-importer-admin * {
				box-sizing: border-box;
			}
			.universal-importer-admin code {
				font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
				font-size: .92em;
			}
			.universal-importer-admin button,
			.universal-importer-admin input,
			.universal-importer-admin textarea,
			.universal-importer-admin select {
				font: inherit;
				color: inherit;
			}
			.universal-importer-top {
				align-items: baseline;
				display: flex;
				gap: 12px;
				justify-content: space-between;
				margin-bottom: 18px;
			}
			.universal-importer-admin h1 {
				font-size: 14px;
				font-weight: 600;
				letter-spacing: .02em;
				line-height: 1.2;
				margin: 0;
			}
			.universal-importer-admin h1 span {
				color: var(--ui-muted);
				font-weight: 400;
			}
			.universal-importer-top-actions {
				display: flex;
				gap: 12px;
			}
			.universal-importer-link-button {
				background: none;
				border: 0;
				color: var(--ui-muted);
				cursor: pointer;
				font: inherit;
				font-size: 12.5px;
				padding: 0;
			}
			.universal-importer-link-button:hover {
				color: var(--ui-ink);
			}
			.universal-importer-lede {
				display: none;
			}
			.universal-importer-strip {
				border-top: 1px solid var(--ui-rule);
				border-bottom: 1px solid var(--ui-rule);
				color: var(--ui-muted);
				display: none;
				font-size: 11.5px;
				margin: -4px 0 14px;
				padding: 10px 0;
			}
			.universal-importer-strip.is-visible {
				display: block;
			}
			.universal-importer-strip-row {
				display: flex;
				flex-wrap: wrap;
				font-variant-numeric: tabular-nums;
				gap: 14px;
			}
			.universal-importer-strip-stage {
				align-items: center;
				display: inline-flex;
				gap: 6px;
				white-space: nowrap;
			}
			.universal-importer-strip-stage .universal-importer-strip-dot {
				background: #dcd1b3;
				border-radius: 50%;
				flex: none;
				height: 8px;
				width: 8px;
			}
			.universal-importer-strip-stage.is-active {
				color: var(--ui-ink);
				font-weight: 600;
			}
			.universal-importer-strip-stage.is-active .universal-importer-strip-dot {
				background: var(--ui-accent);
				box-shadow: 0 0 0 3px #f6e3b2;
			}
			.universal-importer-strip-stage.is-done .universal-importer-strip-dot {
				background: var(--ui-ok);
			}
			.universal-importer-strip-stage.is-blocked .universal-importer-strip-dot {
				animation: universal-importer-pulse-dot 1.2s ease-in-out infinite;
				background: var(--ui-warn);
			}
			@keyframes universal-importer-pulse-dot {
				0%, 100% { box-shadow: 0 0 0 0 rgba(146, 64, 14, .4); }
				50% { box-shadow: 0 0 0 5px rgba(146, 64, 14, 0); }
			}
			.universal-importer-past {
				border-top: 1px solid var(--ui-rule);
				border-bottom: 1px solid var(--ui-rule);
				display: none;
				margin-bottom: 18px;
				padding: 12px 0;
			}
			.universal-importer-past.is-visible {
				display: block;
			}
			.universal-importer-past h2 {
				color: var(--ui-muted);
				font-size: 11px;
				font-weight: 700;
				letter-spacing: .12em;
				margin: 0 0 8px;
				text-transform: uppercase;
			}
			.universal-importer-past-row {
				align-items: center;
				border-top: 1px dotted var(--ui-rule);
				display: flex;
				font-size: 12.5px;
				gap: 12px;
				justify-content: space-between;
				padding: 8px 0;
			}
			.universal-importer-past-row:first-child {
				border-top: 0;
			}
			.universal-importer-past-src {
				font-family: ui-monospace, Menlo, monospace;
				word-break: break-all;
			}
			.universal-importer-past-meta {
				color: var(--ui-muted);
				font-size: 11.5px;
			}
			.universal-importer-past-empty {
				color: var(--ui-muted);
				font-size: 12.5px;
				margin: 0;
				padding: 8px 0;
			}
			.universal-importer-convo {
				display: block;
			}
			.universal-importer-turn {
				animation: universal-importer-fade .18s ease-out;
				border-bottom: 1px dashed var(--ui-rule);
				padding: 14px 0;
			}
			.universal-importer-turn:last-child {
				border-bottom: 0;
			}
			@keyframes universal-importer-fade {
				from { opacity: 0; transform: translateY(4px); }
				to { opacity: 1; transform: none; }
			}
			.universal-importer-speaker {
				align-items: center;
				color: var(--ui-muted);
				display: flex;
				font-size: 10.5px;
				font-weight: 700;
				gap: 8px;
				letter-spacing: .12em;
				margin-bottom: 6px;
				text-transform: uppercase;
			}
			.universal-importer-turn.is-sys .universal-importer-speaker {
				color: var(--ui-accent);
			}
			.universal-importer-turn.is-usr .universal-importer-speaker {
				color: var(--ui-ok);
			}
			.universal-importer-turn.is-dec .universal-importer-speaker {
				color: var(--ui-warn);
			}
			.universal-importer-paused-chip {
				background: var(--ui-warn-bg);
				border: 1px solid var(--ui-warn);
				border-radius: 999px;
				color: var(--ui-warn);
				display: inline-flex;
				font-size: 10px;
				font-weight: 700;
				gap: 5px;
				letter-spacing: .06em;
				padding: 1px 7px;
			}
			.universal-importer-edit {
				background: none;
				border: 0;
				border-radius: 3px;
				color: var(--ui-accent);
				cursor: pointer;
				font-size: 11.5px;
				font-weight: 600;
				letter-spacing: 0;
				margin-left: auto;
				padding: 1px 4px;
				text-transform: none;
			}
			.universal-importer-edit::before {
				content: "\B7 ";
			}
			.universal-importer-edit:hover {
				background: var(--ui-soft);
				text-decoration: underline;
			}
			.universal-importer-turn.is-past .universal-importer-body {
				color: var(--ui-muted);
				font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
				font-size: 13px;
			}
			.universal-importer-turn.is-past .universal-importer-hint {
				display: none;
			}
			.universal-importer-classify-line {
				font-size: 14.5px;
				line-height: 1.55;
			}
			.universal-importer-override {
				background: #fff;
				border: 1px solid var(--ui-rule);
				border-radius: 6px;
				display: grid;
				gap: 2px;
				grid-template-columns: 1fr 1fr;
				margin-top: 8px;
				padding: 6px;
			}
			.universal-importer-override[hidden] {
				display: none;
			}
			.universal-importer-override button {
				background: transparent;
				border: 0;
				border-radius: 4px;
				cursor: pointer;
				font-size: 12.5px;
				padding: 6px 8px;
				text-align: left;
			}
			.universal-importer-override button:hover {
				background: var(--ui-soft);
			}
			.universal-importer-override button.is-on {
				background: var(--ui-soft);
				color: var(--ui-accent);
				font-weight: 600;
			}
			.universal-importer-dom-err {
				color: var(--ui-warn);
				font-size: 11.5px;
				margin: 6px 0 0;
			}
			.universal-importer-dom-err[hidden] {
				display: none;
			}
			.universal-importer-body {
				font-size: 14.5px;
				line-height: 1.55;
			}
			.universal-importer-hint {
				color: var(--ui-muted);
				display: block;
				font-size: 13px;
				margin: 3px 0 0;
			}
			.universal-importer-stack {
				display: grid;
				gap: 10px;
				margin-top: 12px;
			}
			.universal-importer-memo {
				background: #fff;
				border: 1px solid var(--ui-rule);
				border-radius: 6px;
				padding: 12px 13px;
				position: relative;
			}
			.universal-importer-memo.is-focus {
				background: #fffaeb;
				border-color: var(--ui-accent);
			}
			.universal-importer-memo-num {
				background: var(--ui-accent);
				border-radius: 6px 0 6px 0;
				color: #fff;
				font-size: 10px;
				font-weight: 700;
				left: -1px;
				letter-spacing: .08em;
				padding: 2px 7px;
				position: absolute;
				top: -1px;
			}
			.universal-importer-memo h3 {
				font-size: 13.5px;
				font-weight: 600;
				letter-spacing: .01em;
				margin: 0 0 2px 28px;
			}
			.universal-importer-memo-desc {
				color: var(--ui-muted);
				font-size: 12px;
				margin: 0 0 8px 28px;
			}
			.universal-importer-memo .universal-importer-field {
				margin-top: 6px;
			}
			.universal-importer-memo input[type="url"],
			.universal-importer-memo input[type="text"] {
				background: #fff;
				border: 1px solid var(--ui-rule);
				border-radius: 5px;
				padding: 9px 11px;
				width: 100%;
			}
			.universal-importer-memo input:focus {
				border-color: var(--ui-accent);
				outline: 2px solid var(--ui-soft);
				outline-offset: 1px;
			}
			.universal-importer-divider {
				align-items: center;
				color: var(--ui-muted);
				display: flex;
				font-size: 11px;
				font-weight: 700;
				gap: 10px;
				letter-spacing: .1em;
				margin: 2px 0;
				text-transform: uppercase;
			}
			.universal-importer-divider::before,
			.universal-importer-divider::after {
				background: var(--ui-rule);
				content: "";
				flex: 1;
				height: 1px;
			}
			.universal-importer-shortcuts {
				display: flex;
				flex-wrap: wrap;
				gap: 6px;
				margin-top: 10px;
			}
			.universal-importer-chip {
				background: transparent;
				border: 1px solid var(--ui-rule);
				border-radius: 999px;
				color: var(--ui-muted);
				cursor: pointer;
				font-size: 11.5px;
				padding: 3px 9px;
			}
			.universal-importer-chip:hover {
				border-color: var(--ui-accent);
				color: var(--ui-ink);
			}
			.universal-importer-group-label {
				color: var(--ui-muted);
				font-size: 10.5px;
				font-weight: 700;
				letter-spacing: .1em;
				margin: 14px 0 6px;
				text-transform: uppercase;
			}
			.universal-importer-opts {
				display: grid;
				gap: 5px;
			}
			.universal-importer-opt {
				align-items: flex-start;
				background: #fff;
				border: 1px solid var(--ui-rule);
				border-radius: 6px;
				cursor: pointer;
				display: flex;
				gap: 9px;
				padding: 9px 11px;
			}
			.universal-importer-opt.is-on {
				background: #fffaeb;
				border-color: var(--ui-accent);
			}
			.universal-importer-opt input {
				margin-top: 2px;
			}
			.universal-importer-opt b {
				display: block;
				font-size: 13.5px;
				font-weight: 600;
			}
			.universal-importer-opt small {
				color: var(--ui-muted);
				display: block;
				font-size: 12px;
			}
			.universal-importer-line-toggle {
				align-items: center;
				border-top: 1px dotted var(--ui-rule);
				display: flex;
				justify-content: space-between;
				padding: 8px 0;
			}
			.universal-importer-line-toggle:first-of-type {
				border-top: 0;
			}
			.universal-importer-line-toggle b {
				font-size: 13.5px;
			}
			.universal-importer-line-toggle small {
				color: var(--ui-muted);
				display: block;
				font-size: 12px;
			}
			.universal-importer-switch {
				background: #ddd2b3;
				border: 0;
				border-radius: 999px;
				cursor: pointer;
				flex: none;
				height: 18px;
				position: relative;
				width: 32px;
			}
			.universal-importer-switch::after {
				background: #fff;
				border-radius: 50%;
				content: "";
				height: 14px;
				left: 2px;
				position: absolute;
				top: 2px;
				transition: transform .15s;
				width: 14px;
			}
			.universal-importer-switch.is-on {
				background: var(--ui-accent);
			}
			.universal-importer-switch.is-on::after {
				transform: translateX(14px);
			}
			.universal-importer-domain-input {
				background: #fff;
				border: 1px solid var(--ui-rule);
				border-radius: 6px;
				margin-top: 6px;
				padding: 8px 10px;
				width: 100%;
			}
			.universal-importer-btns {
				align-items: center;
				display: flex;
				flex-wrap: wrap;
				gap: 8px;
				margin-top: 12px;
			}
			.universal-importer-btn {
				background: #fff;
				border: 1px solid var(--ui-rule);
				border-radius: 6px;
				cursor: pointer;
				font-size: 13px;
				font-weight: 600;
				padding: 7px 14px;
			}
			.universal-importer-btn:hover {
				border-color: var(--ui-accent);
			}
			.universal-importer-btn:disabled {
				cursor: not-allowed;
				opacity: .5;
			}
			.universal-importer-btn.is-primary {
				background: var(--ui-accent);
				border-color: var(--ui-accent);
				color: #fff;
			}
			.universal-importer-btn.is-primary:hover:not(:disabled) {
				background: #8a5306;
			}
			.universal-importer-btn.is-ghost {
				background: transparent;
				border-color: transparent;
				color: var(--ui-muted);
			}
			.universal-importer-btn.is-ghost:hover {
				color: var(--ui-ink);
			}
			.universal-importer-btn.is-danger {
				border-color: #e3c79a;
				color: var(--ui-warn);
			}
			.universal-importer-btn.is-danger:hover {
				background: var(--ui-warn-bg);
			}
			.universal-importer-start-form {
				margin: 0;
				padding: 0;
			}
			.universal-importer-start-form.is-hidden {
				display: none;
			}
			.universal-importer-source-shortcuts {
				display: flex;
				flex-wrap: wrap;
				gap: 6px;
				margin-top: 10px;
			}
			.universal-importer-source-shortcut {
				background: transparent;
				border: 1px solid var(--ui-rule);
				border-radius: 999px;
				color: var(--ui-muted);
				cursor: pointer;
				font-size: 11.5px;
				padding: 3px 9px;
			}
			.universal-importer-source-shortcut:hover,
			.universal-importer-source-shortcut:focus {
				border-color: var(--ui-accent);
				color: var(--ui-ink);
				outline: none;
			}
			.universal-importer-source-shortcut strong,
			.universal-importer-source-shortcut span {
				display: inline;
				font: inherit;
			}
			.universal-importer-source-shortcut span {
				display: none;
			}
			.universal-importer-dropzone {
				background: #fff;
				border: 1px solid var(--ui-rule);
				border-radius: 6px;
				padding: 12px 13px;
				position: relative;
				transition: background .15s ease, border-color .15s ease, box-shadow .15s ease;
			}
			.universal-importer-dropzone.is-dragging {
				background: var(--ui-soft);
				border-color: var(--ui-accent);
				box-shadow: inset 0 0 0 2px rgba(161, 98, 7, .18), 0 0 0 4px rgba(161, 98, 7, .12);
			}
			.universal-importer-drop-stmt {
				margin: 10px 0 4px;
				padding: 8px 4px 2px;
				text-align: center;
			}
			.universal-importer-drop-stmt-big {
				color: var(--ui-ink);
				font-size: 17px;
				font-weight: 600;
				letter-spacing: .005em;
				line-height: 1.25;
			}
			.universal-importer-drop-stmt-sub {
				color: var(--ui-muted);
				font-size: 12.5px;
				margin-top: 6px;
			}
			.universal-importer-drop-sep {
				color: #bda66f;
				margin: 0 4px;
			}
			.universal-importer-file-pick {
				background: none;
				border: 0;
				color: var(--ui-accent);
				cursor: pointer;
				font: inherit;
				font-size: inherit;
				font-weight: 600;
				padding: 0;
				text-decoration: underline;
				text-decoration-color: rgba(161, 98, 7, .35);
				text-underline-offset: 2px;
			}
			.universal-importer-file-pick:hover {
				text-decoration-color: var(--ui-accent);
			}
			.universal-importer-upload-copy {
				min-width: 0;
			}
			.universal-importer-upload-actions {
				align-items: center;
				display: flex;
				gap: 8px;
				justify-content: center;
				margin-top: 10px;
			}
			.universal-importer-file-input {
				clip: rect(1px, 1px, 1px, 1px);
				clip-path: inset(50%);
				height: 1px;
				overflow: hidden;
				position: absolute;
				white-space: nowrap;
				width: 1px;
			}
			.universal-importer-file-summary {
				color: var(--ui-muted);
				font-size: 12.5px;
				margin-top: 10px;
				overflow: hidden;
				padding: 0 6px;
				text-align: center;
				text-overflow: ellipsis;
				white-space: nowrap;
			}
			.universal-importer-file-summary.has-files {
				color: var(--ui-ok);
				font-weight: 600;
			}
			.universal-importer-upload-hint {
				color: var(--ui-muted);
				font-size: 11.5px;
				margin-top: 10px;
				text-align: center;
			}
			.universal-importer-file-preview {
				color: var(--ui-muted);
				font-size: 12px;
				list-style: none;
				margin: 8px 0 0;
				max-height: 150px;
				overflow: auto;
				padding: 0;
			}
			.universal-importer-file-preview ul {
				list-style: none;
				margin: 0;
				padding-left: 18px;
			}
			.universal-importer-file-preview [role="treeitem"] {
				margin: 0;
				outline: none;
				overflow-wrap: anywhere;
			}
			.universal-importer-file-preview [role="treeitem"][aria-expanded="false"] > [role="group"] {
				display: none;
			}
			.universal-importer-file-preview-item {
				align-items: start;
				border-radius: 4px;
				display: grid;
				gap: 4px;
				grid-template-columns: 14px minmax(0, 1fr);
				line-height: 1.35;
				min-height: 22px;
				padding: 2px 4px;
			}
			.universal-importer-file-preview [role="treeitem"]:focus > .universal-importer-file-preview-item {
				box-shadow: inset 0 0 0 2px var(--ui-accent);
			}
			.universal-importer-file-preview-marker {
				color: #50575e;
				font-family: monospace;
				text-align: center;
			}
			.universal-importer-file-preview-name {
				min-width: 0;
			}
			.universal-importer-github-picker {
				background: #fbfbfc;
				border: 1px solid var(--ui-border);
				border-radius: 8px;
				margin: -6px 0 18px;
				padding: 12px;
			}
			.universal-importer-github-picker[hidden] {
				display: none;
			}
			.universal-importer-github-picker-header {
				align-items: center;
				display: flex;
				flex-wrap: wrap;
				gap: 8px;
				justify-content: space-between;
				margin-bottom: 8px;
			}
			.universal-importer-github-selection {
				color: var(--ui-muted);
				font-size: 12px;
				margin: 0;
			}
			.universal-importer-modal[hidden] {
				display: none;
			}
			.universal-importer-modal {
				align-items: center;
				background: rgba(30, 30, 30, .55);
				bottom: 0;
				display: flex;
				justify-content: center;
				left: 0;
				padding: 24px;
				position: fixed;
				right: 0;
				top: 0;
				z-index: 100000;
			}
			.universal-importer-modal-dialog {
				background: #fff;
				border-radius: 8px;
				box-shadow: 0 22px 70px rgba(0,0,0,.28);
				display: grid;
				grid-template-rows: auto minmax(0, 1fr) auto;
				max-height: min(760px, calc(100vh - 48px));
				max-width: 760px;
				min-height: 420px;
				outline: none;
				overflow: hidden;
				width: min(760px, calc(100vw - 48px));
			}
			.universal-importer-modal-header,
			.universal-importer-modal-footer {
				align-items: center;
				display: flex;
				gap: 12px;
				justify-content: space-between;
				padding: 16px 18px;
			}
			.universal-importer-modal-header {
				border-bottom: 1px solid var(--ui-border);
			}
			.universal-importer-modal-header h2 {
				font-size: 18px;
				line-height: 1.3;
				margin: 0;
			}
			.universal-importer-modal-close {
				align-items: center;
				background: transparent;
				border: 0;
				border-radius: 4px;
				color: #50575e;
				cursor: pointer;
				display: inline-flex;
				font-size: 24px;
				height: 32px;
				justify-content: center;
				line-height: 1;
				padding: 0;
				width: 32px;
			}
			.universal-importer-modal-close:hover,
			.universal-importer-modal-close:focus {
				box-shadow: inset 0 0 0 2px var(--ui-accent);
				outline: none;
			}
			.universal-importer-modal-body {
				display: grid;
				grid-template-rows: auto auto minmax(0, 1fr);
				min-height: 0;
				padding: 16px 18px;
			}
			.universal-importer-github-filter {
				margin: 0 0 12px;
			}
			.universal-importer-github-filter label {
				color: #1d2327;
				display: block;
				font-size: 13px;
				font-weight: 600;
				margin: 0 0 7px;
			}
			.universal-importer-github-filter input[type="search"] {
				border-radius: 6px;
				min-height: 38px;
				width: 100%;
			}
			.universal-importer-github-picker-status {
				color: var(--ui-muted);
				font-size: 12px;
				margin: 0 0 10px;
			}
			.universal-importer-github-tree {
				border: 1px solid var(--ui-border);
				border-radius: 6px;
				list-style: none;
				margin: 0;
				min-height: 180px;
				overflow: auto;
				padding: 6px;
			}
			.universal-importer-github-tree li {
				margin: 0;
			}
			.universal-importer-github-directory {
				background: transparent;
				border: 0;
				border-radius: 4px;
				color: #1d2327;
				cursor: pointer;
				display: block;
				line-height: 1.4;
				min-height: 28px;
				overflow-wrap: anywhere;
				padding-bottom: 5px;
				padding-top: 5px;
				text-align: left;
				width: 100%;
			}
			.universal-importer-github-directory:hover,
			.universal-importer-github-directory:focus {
				background: #f0f6fc;
				box-shadow: inset 0 0 0 2px var(--ui-accent);
				outline: none;
			}
			.universal-importer-github-directory.is-selected {
				background: #f0f6e8;
				box-shadow: inset 3px 0 0 #008a20;
				font-weight: 600;
			}
			.universal-importer-github-empty {
				color: var(--ui-muted);
				margin: 12px;
			}
			.universal-importer-modal-footer {
				border-top: 1px solid var(--ui-border);
			}
			.universal-importer-modal-actions {
				display: flex;
				flex: 0 0 auto;
				gap: 8px;
			}
			.universal-importer-modal-selection {
				color: var(--ui-muted);
				font-size: 12px;
				margin: 0;
				min-width: 0;
				overflow-wrap: anywhere;
			}
			.universal-importer-url-options {
				display: contents;
			}
			.universal-importer-url-intro {
				display: none;
			}
			.universal-importer-option {
				align-items: flex-start;
				background: #fff;
				border: 1px solid var(--ui-rule);
				border-radius: 6px;
				cursor: pointer;
				display: flex;
				gap: 9px;
				margin: 0 0 5px;
				padding: 9px 11px;
			}
			.universal-importer-option.is-on {
				background: #fffaeb;
				border-color: var(--ui-accent);
			}
			.universal-importer-option input {
				margin-top: 2px;
			}
			.universal-importer-option > span {
				min-width: 0;
			}
			.universal-importer-option strong {
				display: block;
				font-size: 13.5px;
				font-weight: 600;
			}
			.universal-importer-option .universal-importer-hint,
			.universal-importer-domain-list .universal-importer-hint {
				color: var(--ui-muted);
				display: block;
				font-size: 12px;
				margin: 0;
			}
			.universal-importer-domain-entry {
				display: block;
				margin-top: 8px;
			}
			.universal-importer-domain-entry span:first-child {
				color: var(--ui-muted);
				display: block;
				font-size: 10.5px;
				font-weight: 700;
				letter-spacing: .1em;
				margin-bottom: 6px;
				text-transform: uppercase;
			}
			.universal-importer-domain-entry input[type="text"] {
				background: #fff;
				border: 1px solid var(--ui-rule);
				border-radius: 6px;
				padding: 8px 10px;
				width: 100%;
			}
			.universal-importer-actions {
				align-items: center;
				display: flex;
				flex-wrap: wrap;
				gap: 8px;
				margin-top: 14px;
			}
			.universal-importer-actions .button {
				background: var(--ui-accent);
				border: 1px solid var(--ui-accent);
				border-radius: 6px;
				color: #fff;
				cursor: pointer;
				font-size: 13px;
				font-weight: 600;
				padding: 7px 14px;
				text-decoration: none;
			}
			.universal-importer-actions .button-primary,
			.universal-importer-actions input[type="submit"] {
				background: var(--ui-accent);
				border-color: var(--ui-accent);
				color: #fff;
				min-height: auto;
				text-shadow: none;
			}
			.universal-importer-actions input[type="submit"]:hover {
				background: #8a5306;
			}
			.universal-importer-sessions {
				display: grid;
				gap: 12px;
			}
			.universal-importer-sessions.is-empty {
				display: none;
			}
			.universal-importer-empty-progress {
				display: none;
			}
			.universal-importer-card {
				background: transparent;
				border: 0;
				border-radius: 0;
				box-shadow: none;
				padding: 0;
			}
			.universal-importer-card.is-importing {
				box-shadow: none;
			}
			.universal-importer-card-header,
			.universal-importer-card-body {
				padding: 0;
			}
			.universal-importer-card-header {
				align-items: baseline;
				border-bottom: 0;
				display: flex;
				flex-wrap: wrap;
				gap: 10px;
				justify-content: space-between;
				margin-bottom: 6px;
			}
			.universal-importer-source-title {
				color: var(--ui-muted);
				font-family: ui-monospace, Menlo, monospace;
				font-size: 12.5px;
				font-weight: 400;
				margin: 0;
				overflow-wrap: anywhere;
				word-break: break-all;
			}
			.universal-importer-meta {
				color: var(--ui-muted);
				font-size: 11.5px;
				margin: 0;
			}
			.universal-importer-status-pill {
				background: var(--ui-warn-bg);
				border: 1px solid var(--ui-warn);
				border-radius: 999px;
				color: var(--ui-warn);
				font-size: 10px;
				font-weight: 700;
				letter-spacing: .06em;
				padding: 1px 7px;
				text-transform: uppercase;
				white-space: nowrap;
			}
			.universal-importer-progressbar {
				background: #f0e4c4;
				border-radius: 999px;
				height: 4px;
				margin: 8px 0;
				overflow: hidden;
				position: relative;
			}
			.universal-importer-progressbar span {
				background: var(--ui-accent);
				display: block;
				height: 100%;
				min-width: 0;
				transition: width .35s ease;
			}
			.universal-importer-progressbar.is-indeterminate span {
				animation: universal-importer-progress-indeterminate 1.4s ease-in-out infinite;
				width: 30%;
			}
			@keyframes universal-importer-progress-indeterminate {
				0% { margin-left: -30%; }
				100% { margin-left: 100%; }
			}
			.universal-importer-current-action {
				font-size: 14.5px;
				font-weight: 500;
				margin: 0 0 6px;
			}
			.universal-importer-attention {
				background: var(--ui-warn-bg);
				border: 1px solid var(--ui-warn);
				border-left: 3px solid var(--ui-warn);
				border-radius: 6px;
				color: var(--ui-warn);
				margin: 10px 0;
				padding: 10px 12px;
			}
			.universal-importer-attention-actions {
				margin: 8px 0 0;
			}
			.universal-importer-stage-title {
				color: var(--ui-muted);
				font-size: 10.5px;
				font-weight: 700;
				letter-spacing: .1em;
				margin: 14px 0 8px;
				text-transform: uppercase;
			}
			.universal-importer-checklist {
				display: grid;
				gap: 4px;
				list-style: none;
				margin: 0 0 10px;
				padding: 0;
			}
			.universal-importer-step {
				align-items: center;
				color: var(--ui-muted);
				display: flex;
				font-size: 12px;
				gap: 8px;
				padding: 4px 0;
			}
			.universal-importer-stage-index {
				background: #dcd1b3;
				border-radius: 50%;
				flex: none;
				height: 8px;
				margin: 0;
				overflow: hidden;
				padding: 0;
				text-indent: -9999px;
				width: 8px;
			}
			.universal-importer-step strong {
				color: var(--ui-ink);
				font-size: 13px;
				font-weight: 500;
			}
			.universal-importer-step span {
				color: var(--ui-muted);
				display: inline;
				font-size: 12px;
				margin-top: 0;
			}
			.universal-importer-stage-note {
				color: var(--ui-muted);
				display: block;
				margin-top: 2px;
				overflow-wrap: anywhere;
			}
			.universal-importer-step-heading {
				align-items: center;
				display: flex;
				flex-wrap: wrap;
				gap: 8px;
			}
			.universal-importer-step-heading strong {
				margin-right: auto;
			}
			.universal-importer-step-heading .universal-importer-step-state {
				background: transparent;
				border: 0;
				color: var(--ui-muted);
				font-size: 10.5px;
				font-weight: 700;
				letter-spacing: .06em;
				margin: 0;
				padding: 0;
				text-transform: uppercase;
			}
			.universal-importer-step[data-state="done"] .universal-importer-stage-index {
				background: var(--ui-ok);
			}
			.universal-importer-step[data-state="active"] .universal-importer-stage-index {
				background: var(--ui-accent);
				box-shadow: 0 0 0 3px #f6e3b2;
			}
			.universal-importer-step[data-state="active"] {
				color: var(--ui-ink);
			}
			.universal-importer-step[data-state="active"] strong {
				font-weight: 600;
			}
			.universal-importer-step[data-state="blocked"] .universal-importer-stage-index {
				animation: universal-importer-pulse-dot 1.2s ease-in-out infinite;
				background: var(--ui-warn);
			}
			.universal-importer-step[data-state="blocked"] {
				color: var(--ui-warn);
			}
			.universal-importer-step[data-state="active"] .universal-importer-step-state,
			.universal-importer-step[data-state="done"] .universal-importer-step-state,
			.universal-importer-step[data-state="blocked"] .universal-importer-step-state {
				background: transparent;
				border: 0;
				color: inherit;
			}
			.universal-importer-log {
				border-top: 1px dashed var(--ui-rule);
				color: var(--ui-muted);
				font-size: 13px;
				margin-top: 10px;
				padding-top: 10px;
			}
			.universal-importer-log strong {
				color: var(--ui-muted);
				font-size: 10.5px;
				font-weight: 700;
				letter-spacing: .1em;
				text-transform: uppercase;
			}
			.universal-importer-log ol {
				list-style: none;
				margin: 6px 0 0;
				max-height: 220px;
				overflow: auto;
				padding: 0;
			}
			.universal-importer-log li {
				display: flex;
				gap: 8px;
				margin-bottom: 4px;
			}
			.universal-importer-log li::before {
				color: var(--ui-accent);
				content: "\B7";
				flex: none;
				font-weight: 700;
				text-align: center;
				width: 10px;
			}
			.universal-importer-decision {
				background: transparent;
				border: 0;
				border-left: 3px solid var(--ui-warn);
				border-radius: 0;
				margin-top: 10px;
				padding: 4px 0 4px 12px;
			}
			.universal-importer-decisions h4 {
				color: var(--ui-warn);
				font-size: 10.5px;
				font-weight: 700;
				letter-spacing: .12em;
				margin: 8px 0 4px;
				text-transform: uppercase;
			}
			.universal-importer-stage-decision {
				margin-top: 8px;
			}
			.universal-importer-stage-decision h4 {
				font-size: 13px;
				margin: 0 0 6px;
			}
			.universal-importer-stage-decision .universal-importer-decision {
				background: transparent;
				margin-top: 0;
			}
			.universal-importer-decision-actions {
				display: flex;
				flex-wrap: wrap;
				gap: 8px;
				margin: 10px 0 0;
			}
			.universal-importer-decision-actions .button {
				background: #fff;
				border: 1px solid var(--ui-rule);
				border-radius: 6px;
				color: var(--ui-ink);
				cursor: pointer;
				font-size: 13px;
				font-weight: 600;
				padding: 7px 14px;
			}
			.universal-importer-decision-actions .button:hover {
				border-color: var(--ui-accent);
			}
			.universal-importer-decision-actions .button-primary {
				background: var(--ui-accent);
				border-color: var(--ui-accent);
				color: #fff;
				text-shadow: none;
			}
			.universal-importer-decision-actions .button-primary:hover {
				background: #8a5306;
			}
			.universal-importer-domain-list {
				display: grid;
				gap: 4px;
				margin: 8px 0;
			}
			.universal-importer-domain-list label {
				align-items: flex-start;
				border-top: 1px dotted var(--ui-rule);
				display: flex;
				gap: 8px;
				margin: 0;
				padding: 8px 0;
			}
			.universal-importer-domain-list label:first-of-type {
				border-top: 0;
			}
			.universal-importer-domain-list input {
				margin-top: 3px;
			}
			.universal-importer-domain-list strong {
				display: block;
				font-family: ui-monospace, Menlo, monospace;
				font-size: 13px;
			}
			.universal-importer-domain-list .universal-importer-hint {
				font-family: ui-monospace, Menlo, monospace;
				font-size: 11.5px;
				word-break: break-all;
			}
			.universal-importer-pipeline {
				margin-top: 8px;
			}
			.universal-importer-pipeline summary {
				color: var(--ui-muted);
				cursor: pointer;
				font-size: 12px;
				list-style: none;
			}
			.universal-importer-pipeline summary::-webkit-details-marker {
				display: none;
			}
			.universal-importer-pipeline summary::before {
				color: var(--ui-accent);
				content: "+ ";
				font-weight: 700;
			}
			.universal-importer-pipeline[open] summary::before {
				content: "\2212 ";
			}
			.universal-importer-pipeline p,
			.universal-importer-pipeline ul {
				color: var(--ui-muted);
				font-size: 12.5px;
				margin: 6px 0;
			}
			.universal-importer-pipeline h4 {
				color: var(--ui-muted);
				font-size: 10.5px;
				font-weight: 700;
				letter-spacing: .1em;
				margin: 10px 0 4px;
				text-transform: uppercase;
			}
			.universal-importer-relationship-warnings {
				background: var(--ui-warn-bg);
				border: 1px solid var(--ui-warn);
				border-radius: 6px;
				color: var(--ui-warn);
				margin: 10px 0;
				padding: 10px 12px;
			}
			.universal-importer-notice {
				border: 1px solid var(--ui-rule);
				border-left: 3px solid var(--ui-accent);
				border-radius: 6px;
				margin: 0 0 14px;
				padding: 8px 12px;
			}
			.universal-importer-notice.notice-error {
				border-color: var(--ui-warn);
				border-left-color: var(--ui-warn);
				color: var(--ui-warn);
			}
			.universal-importer-notice.notice-success {
				border-color: var(--ui-ok);
				border-left-color: var(--ui-ok);
				color: var(--ui-ok);
			}
			.universal-importer-notice.notice-warning {
				border-color: var(--ui-warn);
				border-left-color: var(--ui-warn);
				color: var(--ui-warn);
			}
			.universal-importer-notice p {
				margin: 0;
			}
			.universal-importer-github-picker {
				border: 1px solid var(--ui-rule);
				border-radius: 6px;
				margin: 8px 0 0;
				padding: 8px 10px;
			}
			.universal-importer-github-picker[hidden] {
				display: none;
			}
			.universal-importer-github-picker-header {
				align-items: center;
				display: flex;
				flex-wrap: wrap;
				gap: 8px;
				justify-content: space-between;
				margin-bottom: 4px;
			}
			.universal-importer-github-picker-header strong {
				font-size: 12px;
			}
			.universal-importer-github-picker .button {
				background: #fff;
				border: 1px solid var(--ui-rule);
				border-radius: 6px;
				color: var(--ui-ink);
				cursor: pointer;
				font-size: 12px;
				font-weight: 600;
				padding: 4px 10px;
			}
			.universal-importer-github-selection {
				color: var(--ui-muted);
				font-size: 11.5px;
				margin: 0;
			}
			.universal-importer-modal[hidden] {
				display: none;
			}
			.universal-importer-modal {
				align-items: center;
				background: rgba(31, 41, 55, .45);
				bottom: 0;
				display: flex;
				justify-content: center;
				left: 0;
				padding: 24px;
				position: fixed;
				right: 0;
				top: 0;
				z-index: 100000;
			}
			.universal-importer-modal-dialog {
				background: #fff;
				border: 1px solid var(--ui-rule);
				border-radius: 8px;
				box-shadow: 0 22px 70px rgba(0, 0, 0, .28);
				display: grid;
				grid-template-rows: auto minmax(0, 1fr) auto;
				max-height: min(680px, calc(100vh - 48px));
				max-width: 640px;
				min-height: 360px;
				outline: none;
				overflow: hidden;
				width: min(640px, calc(100vw - 48px));
			}
			.universal-importer-modal-header,
			.universal-importer-modal-footer {
				align-items: center;
				display: flex;
				gap: 12px;
				justify-content: space-between;
				padding: 12px 16px;
			}
			.universal-importer-modal-header {
				border-bottom: 1px solid var(--ui-rule);
			}
			.universal-importer-modal-header h2 {
				font-size: 13.5px;
				font-weight: 600;
				margin: 0;
			}
			.universal-importer-modal-close {
				background: transparent;
				border: 0;
				border-radius: 4px;
				color: var(--ui-muted);
				cursor: pointer;
				font-size: 22px;
				height: 28px;
				line-height: 1;
				width: 28px;
			}
			.universal-importer-modal-close:hover {
				color: var(--ui-ink);
			}
			.universal-importer-modal-body {
				display: grid;
				grid-template-rows: auto auto minmax(0, 1fr);
				min-height: 0;
				padding: 12px 16px;
			}
			.universal-importer-github-filter label {
				color: var(--ui-muted);
				display: block;
				font-size: 10.5px;
				font-weight: 700;
				letter-spacing: .1em;
				margin: 0 0 6px;
				text-transform: uppercase;
			}
			.universal-importer-github-filter input[type="search"] {
				background: #fff;
				border: 1px solid var(--ui-rule);
				border-radius: 5px;
				padding: 8px 10px;
				width: 100%;
			}
			.universal-importer-github-picker-status {
				color: var(--ui-muted);
				font-size: 11.5px;
				margin: 8px 0 6px;
			}
			.universal-importer-github-tree {
				border: 1px solid var(--ui-rule);
				border-radius: 6px;
				list-style: none;
				margin: 0;
				min-height: 180px;
				overflow: auto;
				padding: 4px;
			}
			.universal-importer-github-tree li {
				margin: 0;
			}
			.universal-importer-github-directory {
				background: transparent;
				border: 0;
				border-radius: 4px;
				color: var(--ui-ink);
				cursor: pointer;
				display: block;
				font-size: 12.5px;
				line-height: 1.4;
				min-height: 26px;
				overflow-wrap: anywhere;
				padding: 4px 8px;
				text-align: left;
				width: 100%;
			}
			.universal-importer-github-directory:hover,
			.universal-importer-github-directory:focus {
				background: var(--ui-soft);
				outline: none;
			}
			.universal-importer-github-directory.is-selected {
				background: var(--ui-soft);
				color: var(--ui-accent);
				font-weight: 600;
			}
			.universal-importer-github-empty {
				color: var(--ui-muted);
				margin: 12px;
			}
			.universal-importer-modal-footer {
				border-top: 1px solid var(--ui-rule);
			}
			.universal-importer-modal-actions {
				display: flex;
				flex: 0 0 auto;
				gap: 8px;
			}
			.universal-importer-modal-selection {
				color: var(--ui-muted);
				font-size: 11.5px;
				margin: 0;
				min-width: 0;
				overflow-wrap: anywhere;
			}
			.universal-importer-modal-actions .button {
				background: #fff;
				border: 1px solid var(--ui-rule);
				border-radius: 6px;
				color: var(--ui-ink);
				cursor: pointer;
				font-size: 12.5px;
				font-weight: 600;
				padding: 5px 12px;
			}
			.universal-importer-modal-actions .button-primary {
				background: var(--ui-accent);
				border-color: var(--ui-accent);
				color: #fff;
			}
			.universal-importer-modal-actions .button-primary:disabled {
				cursor: not-allowed;
				opacity: .5;
			}
			.universal-importer-tally {
				display: inline-flex;
				font-size: 13.5px;
				font-variant-numeric: tabular-nums;
				gap: 14px;
				margin-top: 10px;
			}
			.universal-importer-file-preview ul {
				list-style: none;
				margin: 0;
				padding-left: 18px;
			}
			.universal-importer-file-preview [role="treeitem"] {
				margin: 0;
				outline: none;
				overflow-wrap: anywhere;
			}
			.universal-importer-file-preview [role="treeitem"][aria-expanded="false"] > [role="group"] {
				display: none;
			}
			.universal-importer-file-preview-item {
				align-items: start;
				border-radius: 4px;
				display: grid;
				gap: 4px;
				grid-template-columns: 14px minmax(0, 1fr);
				line-height: 1.35;
				min-height: 22px;
				padding: 2px 4px;
			}
			.universal-importer-file-preview [role="treeitem"]:focus > .universal-importer-file-preview-item {
				box-shadow: inset 0 0 0 2px var(--ui-accent);
			}
			.universal-importer-file-preview-marker {
				color: var(--ui-muted);
				font-family: monospace;
				text-align: center;
			}
			.universal-importer-file-preview-name {
				min-width: 0;
			}
			@media (max-width: 600px) {
				.universal-importer-admin {
					padding: 18px 16px 80px;
				}
				.universal-importer-drop-stmt-big {
					font-size: 16px;
				}
			}
		</style>
		<div class="wrap universal-importer-admin">
			<div class="universal-importer-top">
				<h1><?php esc_html_e( 'Universal Importer', 'universal-wordpress-importer' ); ?> <span>· <?php esc_html_e( 'transcript', 'universal-wordpress-importer' ); ?></span></h1>
				<div class="universal-importer-top-actions">
					<button type="button" class="universal-importer-link-button" id="universal-importer-past-toggle"><?php esc_html_e( 'Past imports', 'universal-wordpress-importer' ); ?></button>
				</div>
			</div>
			<div class="universal-importer-strip" id="universal-importer-strip" aria-label="<?php echo esc_attr__( 'Run stages', 'universal-wordpress-importer' ); ?>">
				<div class="universal-importer-strip-row">
					<span class="universal-importer-strip-stage" data-stage-key="read_source"><span class="universal-importer-strip-dot"></span><?php esc_html_e( 'Read source', 'universal-wordpress-importer' ); ?></span>
					<span class="universal-importer-strip-stage" data-stage-key="prepare_content"><span class="universal-importer-strip-dot"></span><?php esc_html_e( 'Prepare content', 'universal-wordpress-importer' ); ?></span>
					<span class="universal-importer-strip-stage" data-stage-key="url_treatment"><span class="universal-importer-strip-dot"></span><?php esc_html_e( 'URL treatment', 'universal-wordpress-importer' ); ?></span>
					<span class="universal-importer-strip-stage" data-stage-key="import_media"><span class="universal-importer-strip-dot"></span><?php esc_html_e( 'Import media', 'universal-wordpress-importer' ); ?></span>
					<span class="universal-importer-strip-stage" data-stage-key="write_pages"><span class="universal-importer-strip-dot"></span><?php esc_html_e( 'Write pages', 'universal-wordpress-importer' ); ?></span>
					<span class="universal-importer-strip-stage" data-stage-key="finish"><span class="universal-importer-strip-dot"></span><?php esc_html_e( 'Finish', 'universal-wordpress-importer' ); ?></span>
				</div>
			</div>
			<div class="universal-importer-past" id="universal-importer-past" aria-label="<?php echo esc_attr__( 'Past imports', 'universal-wordpress-importer' ); ?>">
				<h2><?php esc_html_e( 'Past imports', 'universal-wordpress-importer' ); ?></h2>
				<p class="universal-importer-past-empty"><?php esc_html_e( 'No previous imports yet.', 'universal-wordpress-importer' ); ?></p>
			</div>
			<?php if ( '' !== $error ) : ?>
				<div class="universal-importer-notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>
			<div id="universal-importer-notice" class="universal-importer-notice" style="display:none"><p></p></div>
			<main class="universal-importer-convo" id="universal-importer-convo" aria-live="polite">
				<form id="universal-importer-start-form" class="universal-importer-start-form universal-importer-start<?php echo $has_active_import ? ' is-hidden' : ''; ?>">
					<input type="hidden" name="url_rewrite_mode" id="universal-importer-state-url-mode" value="ask">
					<input type="hidden" name="confirmed_domains" id="universal-importer-state-domains" value="">
					<input type="hidden" name="dry_run" id="universal-importer-state-dry" value="">
					<input type="hidden" name="import_as_drafts" id="universal-importer-state-drafts" value="">
					<input type="hidden" name="source_type" id="universal-importer-state-source-type" value="">
					<div id="universal-importer-turns" class="universal-importer-turns">
					<section class="universal-importer-turn is-sys" id="universal-importer-turn-source" data-turn-key="source">
						<div class="universal-importer-speaker"><?php esc_html_e( 'Importer', 'universal-wordpress-importer' ); ?></div>
						<div class="universal-importer-body">
							<div><?php esc_html_e( 'What should I import?', 'universal-wordpress-importer' ); ?></div>
							<div class="universal-importer-hint"><?php esc_html_e( 'Two ways in. Use one.', 'universal-wordpress-importer' ); ?></div>
							<div class="universal-importer-stack">
								<div class="universal-importer-memo is-focus" id="universal-importer-memo-url">
									<span class="universal-importer-memo-num">1</span>
									<h3><?php esc_html_e( 'Paste a URL', 'universal-wordpress-importer' ); ?></h3>
									<p class="universal-importer-memo-desc"><?php esc_html_e( 'A site, a feed, a repo, a sitemap — anything reachable on the web.', 'universal-wordpress-importer' ); ?></p>
									<div class="universal-importer-field">
										<label for="universal-importer-source" class="screen-reader-text"><?php esc_html_e( 'Source URL', 'universal-wordpress-importer' ); ?></label>
										<input type="url" id="universal-importer-source" name="source" placeholder="<?php echo esc_attr__( 'https://…', 'universal-wordpress-importer' ); ?>" aria-label="<?php echo esc_attr__( 'Source URL', 'universal-wordpress-importer' ); ?>">
									</div>
									<div class="universal-importer-source-shortcuts" aria-label="<?php echo esc_attr__( 'Import source shortcuts', 'universal-wordpress-importer' ); ?>">
										<button type="button" class="universal-importer-source-shortcut" data-source-placeholder="https://github.com/owner/repository/tree/main/docs">
											<strong><?php esc_html_e( 'GitHub repo', 'universal-wordpress-importer' ); ?></strong>
											<span><?php esc_html_e( 'Branch or subdirectory URL.', 'universal-wordpress-importer' ); ?></span>
										</button>
										<button type="button" class="universal-importer-source-shortcut" data-source-placeholder="https://example.com/">
											<strong><?php esc_html_e( 'WordPress site', 'universal-wordpress-importer' ); ?></strong>
											<span><?php esc_html_e( 'REST API, pages, posts, and comments.', 'universal-wordpress-importer' ); ?></span>
										</button>
										<button type="button" class="universal-importer-source-shortcut" data-source-placeholder="https://example.com/feed.xml">
											<strong><?php esc_html_e( 'Feed or OPML', 'universal-wordpress-importer' ); ?></strong>
											<span><?php esc_html_e( 'RSS, Atom, RDF, or a feed list.', 'universal-wordpress-importer' ); ?></span>
										</button>
										<button type="button" class="universal-importer-source-shortcut" data-source-placeholder="https://example.com/sitemap.xml">
											<strong><?php esc_html_e( 'Sitemap', 'universal-wordpress-importer' ); ?></strong>
											<span><?php esc_html_e( 'sitemap.xml or index.', 'universal-wordpress-importer' ); ?></span>
										</button>
										<button type="button" class="universal-importer-source-shortcut" data-source-placeholder="https://example.com/wp-content/export.xml">
											<strong><?php esc_html_e( 'WXR export', 'universal-wordpress-importer' ); ?></strong>
											<span><?php esc_html_e( 'WordPress export XML URL.', 'universal-wordpress-importer' ); ?></span>
										</button>
									</div>
									<div id="universal-importer-github-picker" class="universal-importer-github-picker" hidden>
										<div class="universal-importer-github-picker-header">
											<strong><?php esc_html_e( 'GitHub directory', 'universal-wordpress-importer' ); ?></strong>
											<button type="button" class="button" id="universal-importer-github-browse"><?php esc_html_e( 'Choose directory', 'universal-wordpress-importer' ); ?></button>
										</div>
										<p id="universal-importer-github-selection" class="universal-importer-github-selection" aria-live="polite"></p>
									</div>
								</div>
								<div class="universal-importer-divider" aria-hidden="true"><?php esc_html_e( 'or', 'universal-wordpress-importer' ); ?></div>
								<div id="universal-importer-dropzone" class="universal-importer-memo universal-importer-dropzone" aria-label="<?php echo esc_attr__( 'Drop a file or folder on this card', 'universal-wordpress-importer' ); ?>">
									<span class="universal-importer-memo-num">2</span>
									<h3><?php esc_html_e( 'Upload a file or folder', 'universal-wordpress-importer' ); ?></h3>
									<p class="universal-importer-memo-desc"><?php esc_html_e( 'From this computer. .zip, .epub, .pdf, WXR .xml, or a whole folder.', 'universal-wordpress-importer' ); ?></p>
									<div class="universal-importer-drop-stmt">
										<div class="universal-importer-drop-stmt-big" id="universal-importer-drop-line"><?php esc_html_e( 'Drop a file or folder anywhere on this card', 'universal-wordpress-importer' ); ?></div>
										<div class="universal-importer-drop-stmt-sub"><?php esc_html_e( 'or', 'universal-wordpress-importer' ); ?> <label class="universal-importer-file-pick" for="universal-importer-file-picker"><?php esc_html_e( 'Choose files', 'universal-wordpress-importer' ); ?></label><span class="universal-importer-drop-sep">·</span><label class="universal-importer-file-pick" for="universal-importer-folder-picker"><?php esc_html_e( 'Choose folder', 'universal-wordpress-importer' ); ?></label></div>
									</div>
									<div class="universal-importer-upload-copy">
										<p id="universal-importer-file-summary" class="universal-importer-file-summary" aria-live="polite"></p>
										<ul id="universal-importer-file-preview" class="universal-importer-file-preview" role="tree" aria-label="<?php echo esc_attr__( 'Selected file tree', 'universal-wordpress-importer' ); ?>" aria-live="polite"></ul>
									</div>
									<p class="universal-importer-upload-hint"><?php esc_html_e( 'PDF, EPUB, HTML, Markdown, text, WXR/XML, ZIP, or a folder. Stays in this browser session.', 'universal-wordpress-importer' ); ?></p>
									<div class="universal-importer-upload-actions" hidden>
										<button type="button" class="universal-importer-btn" id="universal-importer-clear-files" disabled><?php esc_html_e( 'Clear selection', 'universal-wordpress-importer' ); ?></button>
									</div>
									<input type="file" id="universal-importer-file-picker" class="universal-importer-file-input" multiple accept=".pdf,.epub,.html,.htm,.md,.markdown,.txt,.xml,.wxr,.zip,application/pdf,application/epub+zip,text/html,text/markdown,text/plain,application/xml,text/xml,application/zip">
									<input type="file" id="universal-importer-folder-picker" class="universal-importer-file-input" multiple webkitdirectory directory>
								</div>
							</div>
							<div class="universal-importer-btns">
								<button type="button" class="universal-importer-btn is-primary" id="universal-importer-source-continue"><?php esc_html_e( 'Continue', 'universal-wordpress-importer' ); ?></button>
								<button type="button" class="universal-importer-btn is-ghost" id="universal-importer-source-clear"><?php esc_html_e( 'Clear', 'universal-wordpress-importer' ); ?></button>
							</div>
						</div>
					</section>
					</div>
					<template id="universal-importer-template-classify">
						<section class="universal-importer-turn is-sys" data-turn-key="classify">
							<div class="universal-importer-speaker"><?php esc_html_e( 'Classify', 'universal-wordpress-importer' ); ?></div>
							<div class="universal-importer-body">
								<div class="universal-importer-classify-line" data-classify-line></div>
								<div class="universal-importer-btns">
									<button type="button" class="universal-importer-btn is-primary" data-action="continue"><?php esc_html_e( 'Continue', 'universal-wordpress-importer' ); ?></button>
									<button type="button" class="universal-importer-btn is-ghost" data-action="toggle-override"><?php esc_html_e( 'Not this? Change…', 'universal-wordpress-importer' ); ?></button>
								</div>
								<div class="universal-importer-override" data-override hidden role="listbox" aria-label="<?php echo esc_attr__( 'Override detected type', 'universal-wordpress-importer' ); ?>">
									<button type="button" data-type="GitHub repository"><?php esc_html_e( 'GitHub repository', 'universal-wordpress-importer' ); ?></button>
									<button type="button" data-type="WordPress site URL"><?php esc_html_e( 'WordPress site URL', 'universal-wordpress-importer' ); ?></button>
									<button type="button" data-type="RSS / Atom / RDF feed"><?php esc_html_e( 'RSS / Atom / RDF feed', 'universal-wordpress-importer' ); ?></button>
									<button type="button" data-type="OPML feed list"><?php esc_html_e( 'OPML feed list', 'universal-wordpress-importer' ); ?></button>
									<button type="button" data-type="Sitemap.xml"><?php esc_html_e( 'Sitemap.xml', 'universal-wordpress-importer' ); ?></button>
									<button type="button" data-type="WP export XML (WXR)"><?php esc_html_e( 'WP export XML (WXR)', 'universal-wordpress-importer' ); ?></button>
									<button type="button" data-type="Remote HTML page"><?php esc_html_e( 'Remote HTML page', 'universal-wordpress-importer' ); ?></button>
									<button type="button" data-type="Local folder (uploaded)"><?php esc_html_e( 'Local folder (uploaded)', 'universal-wordpress-importer' ); ?></button>
									<button type="button" data-type="Local file (uploaded)"><?php esc_html_e( 'Local file (uploaded)', 'universal-wordpress-importer' ); ?></button>
									<button type="button" data-type="Zip archive (uploaded)"><?php esc_html_e( 'Zip archive (uploaded)', 'universal-wordpress-importer' ); ?></button>
									<button type="button" data-type="EPUB (uploaded)"><?php esc_html_e( 'EPUB (uploaded)', 'universal-wordpress-importer' ); ?></button>
									<button type="button" data-type="PDF (uploaded)"><?php esc_html_e( 'PDF (uploaded)', 'universal-wordpress-importer' ); ?></button>
								</div>
							</div>
						</section>
					</template>
					<template id="universal-importer-template-configure">
						<section class="universal-importer-turn is-sys" data-turn-key="configure">
							<div class="universal-importer-speaker"><?php esc_html_e( 'Configure', 'universal-wordpress-importer' ); ?></div>
							<div class="universal-importer-body">
								<div><?php esc_html_e( 'Configure the run.', 'universal-wordpress-importer' ); ?></div>
								<div class="universal-importer-hint"><?php esc_html_e( 'Defaults are sensible.', 'universal-wordpress-importer' ); ?></div>
								<div class="universal-importer-group-label"><?php esc_html_e( 'URL treatment', 'universal-wordpress-importer' ); ?></div>
								<div class="universal-importer-opts" data-url-options>
									<label class="universal-importer-option" data-url-option><input type="radio" name="cfg_url" value="ask"><span><strong><?php esc_html_e( 'Ask when old URLs are found', 'universal-wordpress-importer' ); ?></strong><span class="universal-importer-hint"><?php esc_html_e( 'Recommended — confirm domains mid-run.', 'universal-wordpress-importer' ); ?></span></span></label>
									<label class="universal-importer-option" data-url-option><input type="radio" name="cfg_url" value="preserve"><span><strong><?php esc_html_e( 'Keep URLs unchanged', 'universal-wordpress-importer' ); ?></strong><span class="universal-importer-hint"><?php esc_html_e( 'Links keep pointing to their original site.', 'universal-wordpress-importer' ); ?></span></span></label>
									<label class="universal-importer-option" data-url-option><input type="radio" name="cfg_url" value="rewrite"><span><strong><?php esc_html_e( 'Rewrite listed domains', 'universal-wordpress-importer' ); ?></strong><span class="universal-importer-hint"><?php esc_html_e( 'Skip the mid-run prompt — list domains below.', 'universal-wordpress-importer' ); ?></span></span></label>
								</div>
								<input type="text" data-domains class="universal-importer-domain-input" placeholder="<?php echo esc_attr__( 'example.com, www.example.com', 'universal-wordpress-importer' ); ?>" aria-label="<?php echo esc_attr__( 'Old site domains', 'universal-wordpress-importer' ); ?>" hidden>
								<p class="universal-importer-dom-err" data-domain-err hidden><?php esc_html_e( 'List at least one domain.', 'universal-wordpress-importer' ); ?></p>
								<div class="universal-importer-group-label"><?php esc_html_e( 'Run mode', 'universal-wordpress-importer' ); ?></div>
								<div class="universal-importer-line-toggle"><div><b><?php esc_html_e( 'Dry run', 'universal-wordpress-importer' ); ?></b><small><?php esc_html_e( 'Plan only — no pages written.', 'universal-wordpress-importer' ); ?></small></div><button type="button" class="universal-importer-switch" data-toggle="dry" aria-pressed="false" aria-label="<?php echo esc_attr__( 'Dry run', 'universal-wordpress-importer' ); ?>"></button></div>
								<div class="universal-importer-line-toggle"><div><b><?php esc_html_e( 'Import as drafts', 'universal-wordpress-importer' ); ?></b><small><?php esc_html_e( 'Otherwise published immediately.', 'universal-wordpress-importer' ); ?></small></div><button type="button" class="universal-importer-switch" data-toggle="drafts" aria-pressed="false" aria-label="<?php echo esc_attr__( 'Import as drafts', 'universal-wordpress-importer' ); ?>"></button></div>
								<div class="universal-importer-btns">
									<button type="button" class="universal-importer-btn is-primary" data-action="continue"><?php esc_html_e( 'Review', 'universal-wordpress-importer' ); ?></button>
									<button type="button" class="universal-importer-btn is-ghost" data-action="back"><?php esc_html_e( 'Back', 'universal-wordpress-importer' ); ?></button>
								</div>
							</div>
						</section>
					</template>
					<template id="universal-importer-template-confirm">
						<section class="universal-importer-turn is-sys" data-turn-key="confirm">
							<div class="universal-importer-speaker"><?php esc_html_e( 'Confirm', 'universal-wordpress-importer' ); ?></div>
							<div class="universal-importer-body">
								<div data-confirm-headline></div>
								<div class="universal-importer-hint" data-confirm-detail></div>
								<div class="universal-importer-btns">
									<button type="submit" class="universal-importer-btn is-primary" data-action="start"><?php esc_html_e( 'Start import', 'universal-wordpress-importer' ); ?></button>
									<button type="button" class="universal-importer-btn is-ghost" data-action="back"><?php esc_html_e( 'Edit anything above', 'universal-wordpress-importer' ); ?></button>
								</div>
							</div>
						</section>
					</template>
				</form>
				<p id="universal-importer-empty-progress" class="universal-importer-empty-progress"<?php echo null === $primary_session ? '' : ' style="display:none"'; ?>><?php esc_html_e( 'Choose content above to start an import.', 'universal-wordpress-importer' ); ?></p>
				<div id="universal-importer-sessions" class="universal-importer-sessions<?php echo null === $primary_session ? ' is-empty' : ''; ?>">
					<?php $this->render_session_list( null === $primary_session ? array() : array( $primary_session ) ); ?>
				</div>
			</main>
			<div id="universal-importer-github-modal" class="universal-importer-modal" role="dialog" aria-modal="true" aria-labelledby="universal-importer-github-modal-title" hidden>
				<div class="universal-importer-modal-dialog" tabindex="-1">
					<div class="universal-importer-modal-header">
						<h2 id="universal-importer-github-modal-title"><?php esc_html_e( 'Choose GitHub directory', 'universal-wordpress-importer' ); ?></h2>
						<button type="button" class="universal-importer-modal-close" id="universal-importer-github-close" aria-label="<?php echo esc_attr__( 'Close', 'universal-wordpress-importer' ); ?>">&times;</button>
					</div>
					<div class="universal-importer-modal-body">
						<p class="universal-importer-github-filter">
							<label for="universal-importer-github-search"><?php esc_html_e( 'Filter directories', 'universal-wordpress-importer' ); ?></label>
							<input type="search" id="universal-importer-github-search" autocomplete="off">
						</p>
						<p id="universal-importer-github-picker-status" class="universal-importer-github-picker-status" aria-live="polite"></p>
						<ul id="universal-importer-github-tree" class="universal-importer-github-tree" role="tree" aria-label="<?php echo esc_attr__( 'GitHub repository directories', 'universal-wordpress-importer' ); ?>"></ul>
					</div>
					<div class="universal-importer-modal-footer">
						<p id="universal-importer-github-modal-selection" class="universal-importer-modal-selection" aria-live="polite"></p>
						<span class="universal-importer-modal-actions">
							<button type="button" class="button" id="universal-importer-github-cancel"><?php esc_html_e( 'Cancel', 'universal-wordpress-importer' ); ?></button>
							<button type="button" class="button button-primary" id="universal-importer-github-use"><?php esc_html_e( 'Use directory', 'universal-wordpress-importer' ); ?></button>
						</span>
					</div>
				</div>
			</div>
		</div>
		<script>
		(function() {
			var config = <?php echo wp_json_encode( $config ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode returns JavaScript-safe JSON. ?>;
			var form = document.getElementById('universal-importer-start-form');
			var sourceInput = document.getElementById('universal-importer-source');
			var filePicker = document.getElementById('universal-importer-file-picker');
			var folderPicker = document.getElementById('universal-importer-folder-picker');
			var clearFilesButton = document.getElementById('universal-importer-clear-files');
			var dropzone = document.getElementById('universal-importer-dropzone');
			var fileSummary = document.getElementById('universal-importer-file-summary');
			var filePreview = document.getElementById('universal-importer-file-preview');
			var githubPicker = document.getElementById('universal-importer-github-picker');
			var githubBrowseButton = document.getElementById('universal-importer-github-browse');
			var githubModal = document.getElementById('universal-importer-github-modal');
			var githubModalDialog = githubModal && githubModal.querySelector ? githubModal.querySelector('.universal-importer-modal-dialog') : null;
			var githubCloseButton = document.getElementById('universal-importer-github-close');
			var githubCancelButton = document.getElementById('universal-importer-github-cancel');
			var githubUseButton = document.getElementById('universal-importer-github-use');
			var githubSearch = document.getElementById('universal-importer-github-search');
			var githubPickerStatus = document.getElementById('universal-importer-github-picker-status');
			var githubSelection = document.getElementById('universal-importer-github-selection');
			var githubModalSelection = document.getElementById('universal-importer-github-modal-selection');
			var githubTree = document.getElementById('universal-importer-github-tree');
			var sessions = document.getElementById('universal-importer-sessions');
			var emptyProgress = document.getElementById('universal-importer-empty-progress');
			var notice = document.getElementById('universal-importer-notice');
			var sourceShortcuts = form && form.querySelectorAll ? Array.prototype.slice.call(form.querySelectorAll('.universal-importer-source-shortcut')) : [];
			var activeSessionId = config.primary_session_id || null;
			var timer = null;
			var keepaliveInFlight = false;
			var browserFiles = [];
			var fileTreeSearch = '';
			var fileTreeSearchTimer = null;
			var githubDirectories = [];
			var githubSelectedPath = '';
			var githubSelectedSourceUrl = '';
			var githubPreviousFocus = null;

			function showNotice(message, type) {
				notice.className = 'notice notice-' + type;
				notice.querySelector('p').textContent = message;
				notice.style.display = '';
			}

			function request(action, data) {
				var body;
				var headers = {};
				if (window.FormData && data instanceof FormData) {
					body = data;
					body.set('action', action);
					body.set('nonce', config.nonce);
				} else {
					body = new URLSearchParams();
					body.set('action', action);
					body.set('nonce', config.nonce);
					Object.keys(data || {}).forEach(function(key) {
						body.set(key, data[key]);
					});
					headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
				}
				return fetch(ajaxurl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: headers,
					body: window.FormData && body instanceof FormData ? body : body.toString()
				}).then(function(response) {
					return response.text().then(function(text) {
						var payload;
						try {
							payload = text ? JSON.parse(text) : null;
						} catch (error) {
							throw new Error(nonJsonResponseMessage(response, text));
						}
						if (!response.ok && payload && payload.data && payload.data.message) {
							throw new Error(payload.data.message);
						}
						return payload;
					});
				}).then(function(payload) {
					if (!payload || !payload.success) {
						throw new Error(payload && payload.data && payload.data.message ? payload.data.message : 'Importer request failed.');
					}
					return payload.data;
				});
			}

			function nonJsonResponseMessage(response, text) {
				var status = response && response.status ? 'HTTP ' + response.status + ': ' : '';
				var excerpt = String(text || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
				if (excerpt.length > 180) {
					excerpt = excerpt.slice(0, 177) + '...';
				}
				return status + '<?php echo esc_js( __( 'Importer request returned a non-JSON response.', 'universal-wordpress-importer' ) ); ?>' + (excerpt ? ' ' + excerpt : '');
			}

			function isGitHubRepositoryInput(value) {
				return /^https?:\/\/github\.com\/[^\/\s]+\/[^\/\s]+/i.test(String(value || '').trim());
			}

			function syncGithubPickerVisibility() {
				if (!githubPicker) {
					return;
				}
				var visible = browserFiles.length < 1 && isGitHubRepositoryInput(sourceInput.value || '');
				if (visible) {
					githubPicker.removeAttribute('hidden');
					if (githubSelection && !githubSelection.textContent) {
						githubSelection.textContent = '<?php echo esc_js( __( 'Selected: repository root', 'universal-wordpress-importer' ) ); ?>';
					}
				} else {
					githubPicker.setAttribute('hidden', 'hidden');
					closeGithubDirectoryModal(false);
					if (githubPickerStatus) {
						githubPickerStatus.textContent = '';
					}
					if (githubSelection) {
						githubSelection.textContent = '';
					}
					if (githubTree) {
						githubTree.innerHTML = '';
					}
					githubDirectories = [];
				}
			}

			function isGithubModalOpen() {
				return githubModal && !githubModal.hasAttribute('hidden');
			}

			function openGithubDirectoryModal() {
				if (!githubModal) {
					return;
				}
				githubPreviousFocus = document.activeElement || githubBrowseButton || sourceInput;
				githubModal.removeAttribute('hidden');
				if (githubSearch) {
					githubSearch.value = '';
				}
				if (githubModalDialog && githubModalDialog.focus) {
					githubModalDialog.focus();
				}
				if (githubSearch && githubSearch.focus) {
					githubSearch.focus();
				}
			}

			function closeGithubDirectoryModal(restoreFocus) {
				if (!githubModal || !isGithubModalOpen()) {
					return;
				}
				githubModal.setAttribute('hidden', 'hidden');
				if (restoreFocus !== false && githubPreviousFocus && githubPreviousFocus.focus) {
					githubPreviousFocus.focus();
				}
			}

			function loadGithubDirectories() {
				var source = String(sourceInput.value || '').trim();
				if (!isGitHubRepositoryInput(source)) {
					showNotice('<?php echo esc_js( __( 'Enter a GitHub repository URL before browsing directories.', 'universal-wordpress-importer' ) ); ?>', 'error');
					return;
				}

				openGithubDirectoryModal();
				if (githubBrowseButton) {
					githubBrowseButton.disabled = true;
				}
				if (githubPickerStatus) {
					githubPickerStatus.textContent = '<?php echo esc_js( __( 'Loading directories...', 'universal-wordpress-importer' ) ); ?>';
				}
				updateGithubSelectedSummary('', '');
				if (githubTree) {
					githubTree.innerHTML = '';
				}

				request('<?php echo esc_js( self::AJAX_GITHUB_DIRS ); ?>', { source: source }).then(function(data) {
					renderGithubDirectories(data);
				}).catch(function(error) {
					var fallback = '<?php echo esc_js( __( "Couldn't list directories. You can close this and continue with the URL as-is — picking a directory is optional.", 'universal-wordpress-importer' ) ); ?>';
					if (githubPickerStatus) {
						githubPickerStatus.textContent = error.message + ' ' + fallback;
					}
					showNotice(error.message + ' ' + fallback, 'error');
				}).then(function() {
					if (githubBrowseButton) {
						githubBrowseButton.disabled = false;
					}
				});
			}

			function renderGithubDirectories(data) {
				githubDirectories = data && data.directories ? data.directories : [];
				githubSelectedPath = data && typeof data.selected_path === 'string' ? data.selected_path : '';
				githubSelectedSourceUrl = data && data.selected_source_url ? data.selected_source_url : '';
				renderGithubDirectoryRows();
			}

			function filteredGithubDirectories() {
				var query = githubSearch ? String(githubSearch.value || '').toLowerCase().trim() : '';

				if (!query) {
					return githubDirectories;
				}

				return githubDirectories.filter(function(directory) {
					return String(directory.path || '').toLowerCase().indexOf(query) !== -1 || String(directory.name || '').toLowerCase().indexOf(query) !== -1;
				});
			}

			function renderGithubDirectoryRows() {
				var directories = filteredGithubDirectories();

				if (githubPickerStatus) {
					githubPickerStatus.textContent = directories.length + ' <?php echo esc_js( __( 'directories shown.', 'universal-wordpress-importer' ) ); ?>';
				}
				updateGithubSelectedSummary(githubSelectedPath, githubSelectedSourceUrl);
				if (!githubTree) {
					return;
				}

				if (!directories.length) {
					githubTree.innerHTML = '<li class="universal-importer-github-empty"><?php echo esc_js( __( 'No directories match this filter.', 'universal-wordpress-importer' ) ); ?></li>';
					return;
				}

				githubTree.innerHTML = directories.map(function(directory) {
					var path = directory.path || '';
					var name = path ? directory.name || path : '<?php echo esc_js( __( 'Repository root', 'universal-wordpress-importer' ) ); ?>';
					var depth = Math.max(0, Number(directory.depth || 0));
					var selected = path === githubSelectedPath ? ' is-selected' : '';
					var padding = 10 + depth * 18;
					var tabIndex = path === githubSelectedPath || (!githubSelectedPath && directories.indexOf(directory) === 0) ? '0' : '-1';
					return '<li role="none"><button type="button" role="treeitem" tabindex="' + tabIndex + '" class="universal-importer-github-directory' + selected + '" data-source-url="' + escapeHtml(directory.source_url || '') + '" data-path="' + escapeHtml(path) + '" style="padding-left:' + padding + 'px">' + escapeHtml(name) + '</button></li>';
				}).join('');
			}

			function chooseGithubDirectory(button) {
				var sourceUrl = button.getAttribute('data-source-url') || '';
				var path = button.getAttribute('data-path') || '';

				if (!sourceUrl) {
					return;
				}

				githubSelectedPath = path;
				githubSelectedSourceUrl = sourceUrl;
				updateGithubSelectedSummary(path, sourceUrl);
				if (githubTree && githubTree.querySelectorAll) {
					Array.prototype.slice.call(githubTree.querySelectorAll('.universal-importer-github-directory')).forEach(function(item) {
						if (item.classList && item.classList.remove) {
							item.classList.remove('is-selected');
						}
					});
				}
				if (button.classList && button.classList.add) {
					button.classList.add('is-selected');
				}
				focusGithubDirectory(button);
			}

			function updateGithubSelectedSummary(path, sourceUrl) {
				var label = path ? '<?php echo esc_js( __( 'Selected:', 'universal-wordpress-importer' ) ); ?> ' + path : '<?php echo esc_js( __( 'Selected: repository root', 'universal-wordpress-importer' ) ); ?>';
				if (githubSelection) {
					githubSelection.textContent = label;
				}
				if (githubModalSelection) {
					githubModalSelection.textContent = sourceUrl ? label + ' · ' + sourceUrl : label;
				}
				if (githubUseButton) {
					githubUseButton.disabled = !sourceUrl;
				}
			}

			function applyGithubDirectorySelection() {
				if (!githubSelectedSourceUrl) {
					return;
				}
				sourceInput.value = githubSelectedSourceUrl;
				closeGithubDirectoryModal(true);
				syncGithubPickerVisibility();
			}

			function githubDirectoryButtons() {
				if (!githubTree || !githubTree.querySelectorAll) {
					return [];
				}
				return Array.prototype.slice.call(githubTree.querySelectorAll('.universal-importer-github-directory'));
			}

			function focusGithubDirectory(button) {
				githubDirectoryButtons().forEach(function(item) {
					item.tabIndex = -1;
					if (item.setAttribute) {
						item.setAttribute('tabindex', '-1');
					}
				});
				button.tabIndex = 0;
				if (button.setAttribute) {
					button.setAttribute('tabindex', '0');
				}
				if (button.focus) {
					button.focus();
				}
			}

			function moveGithubDirectoryFocus(current, offset) {
				var buttons = githubDirectoryButtons();
				var index = buttons.indexOf(current);
				if (-1 === index || !buttons.length) {
					return;
				}
				index = Math.max(0, Math.min(buttons.length - 1, index + offset));
				focusGithubDirectory(buttons[index]);
			}

			function focusFirstGithubDirectory() {
				var buttons = githubDirectoryButtons();
				if (buttons.length) {
					focusGithubDirectory(buttons[0]);
				}
			}

			function focusLastGithubDirectory() {
				var buttons = githubDirectoryButtons();
				if (buttons.length) {
					focusGithubDirectory(buttons[buttons.length - 1]);
				}
			}

			function githubModalFocusableElements() {
				if (!githubModal || !githubModal.querySelectorAll) {
					return [];
				}
				return Array.prototype.slice.call(githubModal.querySelectorAll('button, input, [tabindex]:not([tabindex="-1"])')).filter(function(element) {
					return !element.disabled && !(element.hasAttribute && element.hasAttribute('hidden'));
				});
			}

			function trapGithubModalFocus(event) {
				var focusable = githubModalFocusableElements();
				var currentIndex = focusable.indexOf(document.activeElement);

				if (!focusable.length) {
					event.preventDefault();
					if (githubModalDialog && githubModalDialog.focus) {
						githubModalDialog.focus();
					}
					return;
				}

				if (event.shiftKey && currentIndex <= 0) {
					event.preventDefault();
					focusable[focusable.length - 1].focus();
					return;
				}

				if (!event.shiftKey && (currentIndex === -1 || currentIndex === focusable.length - 1)) {
					event.preventDefault();
					focusable[0].focus();
				}
			}

			function handleGithubModalKeydown(event) {
				if (event.key === 'Escape') {
					event.preventDefault();
					closeGithubDirectoryModal(true);
					return;
				}

				if (event.key === 'Tab') {
					trapGithubModalFocus(event);
				}
			}

			function filePath(file) {
				return file.universalImporterRelativePath || file.webkitRelativePath || file.name;
			}

			function countFilesByExtension(files, extension) {
				return files.filter(function(file) {
					return filePath(file).toLowerCase().slice(-extension.length) === extension;
				}).length;
			}

			function buildFileTree(files) {
				var root = {
					name: '',
					path: '',
					type: 'directory',
					children: {}
				};
				files.forEach(function(file) {
					var parts = filePath(file).split('/').filter(Boolean);
					var node = root;
					parts.forEach(function(part, index) {
						var type = index === parts.length - 1 ? 'file' : 'directory';
						var path = node.path ? node.path + '/' + part : part;
						if (!node.children[part]) {
							node.children[part] = {
								name: part,
								path: path,
								type: type,
								children: {}
							};
						}
						node = node.children[part];
					});
				});
				return root;
			}

			function sortedTreeChildren(node) {
				return Object.keys(node.children || {}).map(function(key) {
					return node.children[key];
				}).sort(function(a, b) {
					if (a.type !== b.type) {
						return a.type === 'directory' ? -1 : 1;
					}
					return a.name.localeCompare(b.name);
				});
			}

			function setAttribute(element, name, value) {
				if (element.setAttribute) {
					element.setAttribute(name, value);
				}
				element[name] = value;
			}

			function appendTreeNode(parent, node, index) {
				var item = document.createElement('li');
				var row = document.createElement('span');
				var marker = document.createElement('span');
				var label = document.createElement('span');
				var children = sortedTreeChildren(node);

				setAttribute(item, 'role', 'treeitem');
				setAttribute(item, 'tabindex', index === 0 ? '0' : '-1');
				setAttribute(item, 'data-tree-path', node.path);
				setAttribute(item, 'data-tree-label', node.name.toLowerCase());
				setAttribute(item, 'data-tree-kind', node.type);
				if (node.type === 'directory') {
					setAttribute(item, 'aria-expanded', 'true');
				}
				row.className = 'universal-importer-file-preview-item';
				marker.className = 'universal-importer-file-preview-marker';
				marker.textContent = node.type === 'directory' ? '-' : '';
				label.className = 'universal-importer-file-preview-name';
				label.textContent = node.name;
				row.appendChild(marker);
				row.appendChild(label);
				item.appendChild(row);

				if (children.length) {
					var group = document.createElement('ul');
					setAttribute(group, 'role', 'group');
					children.forEach(function(child, childIndex) {
						appendTreeNode(group, child, index + childIndex + 1);
					});
					item.appendChild(group);
				}

				parent.appendChild(item);
			}

			function renderFilePreview(files) {
				var previewFiles = files.slice(0, 120);
				var root = buildFileTree(previewFiles);
				var children = sortedTreeChildren(root);
				filePreview.innerHTML = '';
				children.forEach(function(child, index) {
					appendTreeNode(filePreview, child, index);
				});
				if (files.length > previewFiles.length) {
					var remaining = document.createElement('li');
					setAttribute(remaining, 'role', 'treeitem');
					setAttribute(remaining, 'tabindex', children.length ? '-1' : '0');
					setAttribute(remaining, 'data-tree-label', 'more');
					setAttribute(remaining, 'data-tree-kind', 'summary');
					remaining.textContent = '+' + (files.length - previewFiles.length) + ' more';
					filePreview.appendChild(remaining);
				}
			}

			function previewTreeItems() {
				if (!filePreview.querySelectorAll) {
					return [];
				}
				return Array.prototype.slice.call(filePreview.querySelectorAll('[role="treeitem"]')).filter(function(item) {
					return isPreviewTreeItemVisible(item);
				});
			}

			function isPreviewTreeItemVisible(item) {
				var node = item.parentElement;
				while (node && node !== filePreview) {
					if (node.getAttribute && node.getAttribute('role') === 'treeitem' && node.getAttribute('aria-expanded') === 'false') {
						return false;
					}
					node = node.parentElement;
				}
				return true;
			}

			function focusPreviewTreeItem(item) {
				previewTreeItems().forEach(function(treeItem) {
					treeItem.tabIndex = -1;
					if (treeItem.setAttribute) {
						treeItem.setAttribute('tabindex', '-1');
					}
				});
				item.tabIndex = 0;
				if (item.setAttribute) {
					item.setAttribute('tabindex', '0');
				}
				if (item.focus) {
					item.focus();
				}
			}

			function setTreeItemExpanded(item, expanded) {
				if (!item || item.getAttribute('data-tree-kind') !== 'directory') {
					return;
				}
				item.setAttribute('aria-expanded', expanded ? 'true' : 'false');
				var marker = item.querySelector ? item.querySelector('.universal-importer-file-preview-marker') : null;
				if (marker) {
					marker.textContent = expanded ? '-' : '+';
				}
			}

			function parentTreeItem(item) {
				var node = item ? item.parentElement : null;
				while (node && node !== filePreview) {
					if (node.getAttribute && node.getAttribute('role') === 'treeitem') {
						return node;
					}
					node = node.parentElement;
				}
				return null;
			}

			function movePreviewFocus(item, offset) {
				var items = previewTreeItems();
				var index = items.indexOf(item);
				if (-1 === index) {
					return;
				}
				index = Math.max(0, Math.min(items.length - 1, index + offset));
				focusPreviewTreeItem(items[index]);
			}

			function firstChildTreeItem(item) {
				if (!item || !item.querySelector) {
					return null;
				}
				return item.querySelector('[role="group"] > [role="treeitem"]');
			}

			function findPreviewTreeItemByPrefix(current, prefix) {
				var items = previewTreeItems();
				var start = Math.max(0, items.indexOf(current));
				var ordered = items.slice(start + 1).concat(items.slice(0, start + 1));
				for (var index = 0; index < ordered.length; index++) {
					if ((ordered[index].getAttribute('data-tree-label') || '').indexOf(prefix) === 0) {
						return ordered[index];
					}
				}
				return null;
			}

			function setBrowserFiles(files, sourceLabel) {
				browserFiles = files || [];
				sourceInput.required = browserFiles.length < 1;
				clearFilesButton.disabled = browserFiles.length < 1;
				var clearActions = document.querySelector ? document.querySelector('.universal-importer-upload-actions') : null;
				if (clearActions) {
					if (browserFiles.length) {
						clearActions.removeAttribute('hidden');
					} else {
						clearActions.setAttribute('hidden', 'hidden');
					}
				}
				syncGithubPickerVisibility();
				if (!browserFiles.length) {
					fileSummary.textContent = '';
					if (fileSummary.classList && fileSummary.classList.remove) {
						fileSummary.classList.remove('has-files');
					}
					filePreview.innerHTML = '';
					return;
				}
				var pdfCount = countFilesByExtension(browserFiles, '.pdf');
				var summary = browserFiles.length + ' file' + (browserFiles.length === 1 ? '' : 's') + ' ready';
				if (sourceLabel) {
					summary += ' from ' + sourceLabel;
				}
				if (pdfCount) {
					summary += ' · ' + pdfCount + ' PDF' + (pdfCount === 1 ? '' : 's');
				}
				fileSummary.textContent = summary + '.';
				if (fileSummary.classList && fileSummary.classList.add) {
					fileSummary.classList.add('has-files');
				}
				renderFilePreview(browserFiles);
				if (typeof focusMemo === 'function') {
					focusMemo('upload');
				}
			}

			var memoUrl = document.getElementById('universal-importer-memo-url');
			var memoUpload = dropzone;
			var dropLine = document.getElementById('universal-importer-drop-line');
			var originalDropText = dropLine ? dropLine.textContent : '';
			var pastToggle = document.getElementById('universal-importer-past-toggle');
			var pastPanel = document.getElementById('universal-importer-past');

			function focusMemo(which) {
				if (memoUrl && memoUrl.classList) {
					if (which === 'url') {
						memoUrl.classList.add('is-focus');
					} else {
						memoUrl.classList.remove('is-focus');
					}
				}
				if (memoUpload && memoUpload.classList) {
					if (which === 'upload') {
						memoUpload.classList.add('is-focus');
					} else {
						memoUpload.classList.remove('is-focus');
					}
				}
			}

			sourceShortcuts.forEach(function(button) {
				button.addEventListener('click', function() {
					var placeholder = button.getAttribute('data-source-placeholder') || '';
					if (placeholder) {
						sourceInput.value = placeholder;
					}
					if (sourceInput.focus) {
						sourceInput.focus();
					}
					setBrowserFiles([], '');
					focusMemo('url');
					syncGithubPickerVisibility();
				});
			});

			sourceInput.addEventListener('focus', function() { focusMemo('url'); });
			sourceInput.addEventListener('input', function() {
				focusMemo('url');
				syncGithubPickerVisibility();
			});
			sourceInput.addEventListener('change', syncGithubPickerVisibility);

			if (pastToggle && pastPanel) {
				pastToggle.addEventListener('click', function() {
					pastPanel.classList.toggle('is-visible');
				});
			}

			// URL rewrite radios live inside the Configure template; bindings happen when that turn is rendered.

			if (githubBrowseButton) {
				githubBrowseButton.addEventListener('click', loadGithubDirectories);
			}

			if (githubTree) {
				githubTree.addEventListener('click', function(event) {
					var button = event.target.closest ? event.target.closest('.universal-importer-github-directory') : null;
					if (button) {
						chooseGithubDirectory(button);
					}
				});
				githubTree.addEventListener('keydown', function(event) {
					var button = event.target.closest ? event.target.closest('.universal-importer-github-directory') : null;
					if (!button) {
						return;
					}

					if (event.key === 'ArrowDown') {
						event.preventDefault();
						moveGithubDirectoryFocus(button, 1);
						return;
					}
					if (event.key === 'ArrowUp') {
						event.preventDefault();
						moveGithubDirectoryFocus(button, -1);
						return;
					}
					if (event.key === 'Home') {
						event.preventDefault();
						focusFirstGithubDirectory();
						return;
					}
					if (event.key === 'End') {
						event.preventDefault();
						focusLastGithubDirectory();
						return;
					}
					if (event.key === ' ' || event.key === 'Enter') {
						event.preventDefault();
						chooseGithubDirectory(button);
					}
				});
			}

			if (githubSearch) {
				githubSearch.addEventListener('input', renderGithubDirectoryRows);
				githubSearch.addEventListener('keydown', function(event) {
					if (event.key === 'ArrowDown') {
						event.preventDefault();
						focusFirstGithubDirectory();
					}
				});
			}

			if (githubCloseButton) {
				githubCloseButton.addEventListener('click', function() {
					closeGithubDirectoryModal(true);
				});
			}

			if (githubCancelButton) {
				githubCancelButton.addEventListener('click', function() {
					closeGithubDirectoryModal(true);
				});
			}

			if (githubUseButton) {
				githubUseButton.addEventListener('click', applyGithubDirectorySelection);
			}

			if (githubModal) {
				githubModal.addEventListener('click', function(event) {
					if (event.target === githubModal) {
						closeGithubDirectoryModal(true);
					}
				});
				githubModal.addEventListener('keydown', handleGithubModalKeydown);
			}

			filePreview.addEventListener('click', function(event) {
				var item = event.target.closest ? event.target.closest('[role="treeitem"]') : null;
				if (!item || item.getAttribute('data-tree-kind') !== 'directory') {
					return;
				}
				var expanded = item.getAttribute('aria-expanded') !== 'false';
				setTreeItemExpanded(item, !expanded);
				focusPreviewTreeItem(item);
			});

			filePreview.addEventListener('keydown', function(event) {
				var item = event.target.closest ? event.target.closest('[role="treeitem"]') : null;
				if (!item) {
					return;
				}

				if (event.key === 'ArrowDown') {
					event.preventDefault();
					movePreviewFocus(item, 1);
					return;
				}
				if (event.key === 'ArrowUp') {
					event.preventDefault();
					movePreviewFocus(item, -1);
					return;
				}
				if (event.key === 'Home') {
					event.preventDefault();
					var first = previewTreeItems()[0];
					if (first) {
						focusPreviewTreeItem(first);
					}
					return;
				}
				if (event.key === 'End') {
					event.preventDefault();
					var items = previewTreeItems();
					if (items.length) {
						focusPreviewTreeItem(items[items.length - 1]);
					}
					return;
				}
				if (event.key === 'ArrowLeft') {
					event.preventDefault();
					if (item.getAttribute('data-tree-kind') === 'directory' && item.getAttribute('aria-expanded') !== 'false') {
						setTreeItemExpanded(item, false);
						return;
					}
					var parent = parentTreeItem(item);
					if (parent) {
						focusPreviewTreeItem(parent);
					}
					return;
				}
				if (event.key === 'ArrowRight') {
					event.preventDefault();
					if (item.getAttribute('data-tree-kind') !== 'directory') {
						return;
					}
					if (item.getAttribute('aria-expanded') === 'false') {
						setTreeItemExpanded(item, true);
						return;
					}
					var child = firstChildTreeItem(item);
					if (child) {
						focusPreviewTreeItem(child);
					}
					return;
				}
				if (event.key === ' ' || event.key === 'Enter') {
					if (item.getAttribute('data-tree-kind') === 'directory') {
						event.preventDefault();
						setTreeItemExpanded(item, item.getAttribute('aria-expanded') === 'false');
					}
					return;
				}
				if (event.key && event.key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey) {
					fileTreeSearch += event.key.toLowerCase();
					if (fileTreeSearchTimer && window.clearTimeout) {
						window.clearTimeout(fileTreeSearchTimer);
					}
					if (window.setTimeout) {
						fileTreeSearchTimer = window.setTimeout(function() {
							fileTreeSearch = '';
						}, 800);
					}
					var match = findPreviewTreeItemByPrefix(item, fileTreeSearch);
					if (match) {
						focusPreviewTreeItem(match);
					}
				}
			});

			function readDirectoryEntries(reader) {
				return new Promise(function(resolve, reject) {
					var entries = [];
					function readBatch() {
						reader.readEntries(function(batch) {
							if (!batch.length) {
								resolve(entries);
								return;
							}
							entries = entries.concat(Array.prototype.slice.call(batch));
							readBatch();
						}, reject);
					}
					readBatch();
				});
			}

			function entryFiles(entry, path) {
				path = path || '';
				if (entry.isFile) {
					return new Promise(function(resolve, reject) {
						entry.file(function(file) {
							file.universalImporterRelativePath = path + file.name;
							resolve([file]);
						}, reject);
					});
				}
				if (entry.isDirectory) {
					return readDirectoryEntries(entry.createReader()).then(function(entries) {
						return Promise.all(entries.map(function(child) {
							return entryFiles(child, path + entry.name + '/');
						})).then(function(groups) {
							return groups.reduce(function(all, group) {
								return all.concat(group);
							}, []);
						});
					});
				}
				return Promise.resolve([]);
			}

			function filesFromDrop(dataTransfer) {
				var items = dataTransfer.items ? Array.prototype.slice.call(dataTransfer.items) : [];
				var entries = items.map(function(item) {
					return item.webkitGetAsEntry ? item.webkitGetAsEntry() : null;
				}).filter(Boolean);
				if (!entries.length) {
					return Promise.resolve(Array.prototype.slice.call(dataTransfer.files || []));
				}
				return Promise.all(entries.map(function(entry) {
					return entryFiles(entry, '');
				})).then(function(groups) {
					return groups.reduce(function(all, group) {
						return all.concat(group);
					}, []);
				});
			}

			function renderSession(session) {
				var dashboard = session.dashboard || {};
				var summary = dashboard.summary || { total: 0, completed: 0, errors: 0 };
				var percent = Math.max(0, Math.min(100, Number(dashboard.percentage || 0)));
				var total = summary.total || '?';
				var progressClass = dashboard.indeterminate ? ' is-indeterminate' : '';
				var progressNote = dashboard.progress_note || '';
				var displayStatus = dashboard.attention_message ? '<?php echo esc_js( __( 'Needs attention', 'universal-wordpress-importer' ) ); ?>' : (dashboard.status_label || session.status);
				var mode = session.dry_run ? '<?php echo esc_js( __( 'Dry run', 'universal-wordpress-importer' ) ); ?>' : (session.post_status === 'draft' ? '<?php echo esc_js( __( 'Creates drafts', 'universal-wordpress-importer' ) ); ?>' : '<?php echo esc_js( __( 'Publishes pages', 'universal-wordpress-importer' ) ); ?>');
				var importingClass = isImportLocked(session) ? ' is-importing' : '';
				var html = '<section class="universal-importer-card' + importingClass + '" data-session-id="' + escapeHtml(session.id) + '">';
				html += '<div class="universal-importer-card-header">';
				html += '<div><h3 class="universal-importer-source-title">' + escapeHtml(session.source) + '</h3>';
				html += '<p class="universal-importer-meta">' + mode + '</p></div>';
				html += '<span class="universal-importer-status-pill">' + escapeHtml(displayStatus) + '</span>';
				html += '</div><div class="universal-importer-card-body">';
				html += '<p class="universal-importer-current-action">' + escapeHtml(dashboard.current_action || '<?php echo esc_js( __( 'Checking import state.', 'universal-wordpress-importer' ) ); ?>') + '</p>';
				html += '<div class="universal-importer-progressbar' + progressClass + '" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' + percent + '"><span style="width:' + percent + '%"></span></div>';
				html += '<p class="universal-importer-meta">';
				if (progressNote) {
					html += escapeHtml(progressNote);
				} else {
					html += percent + '% · ' + summary.completed + ' / ' + total + ' <?php echo esc_js( __( 'items complete', 'universal-wordpress-importer' ) ); ?>';
				}
				if (summary.errors) {
					html += ' · ' + summary.errors + ' <?php echo esc_js( __( 'errors', 'universal-wordpress-importer' ) ); ?>';
				}
				html += '</p>';
				if (dashboard.attention_message) {
					html += '<div class="notice notice-warning inline universal-importer-attention"><p><strong><?php echo esc_js( __( 'Needs attention', 'universal-wordpress-importer' ) ); ?></strong><br>' + escapeHtml(dashboard.attention_message) + '</p>';
					if (canStartAnotherImport(session)) {
						html += '<p class="universal-importer-attention-actions"><button type="button" class="button button-primary universal-importer-start-over"><?php echo esc_js( __( 'Start another import', 'universal-wordpress-importer' ) ); ?></button></p>';
					}
					html += '</div>';
				}
				html += renderChecklist(dashboard.checklist || [], session);
				if (session.relationship_warnings && session.relationship_warnings.length) {
					html += renderRelationshipWarnings(session.relationship_warnings);
				}
				if (remainingDecisions(session).length) {
					html += renderDecisions(session, remainingDecisions(session));
				}
				html += renderActivityLog(dashboard.activity_log || session.recent_events || []);
				html += renderPipeline(session);
				if (session.status === 'done' && dashboard.imported_content_url) {
					html += '<p><a class="button button-primary" href="' + escapeHtml(dashboard.imported_content_url) + '"><?php echo esc_js( __( 'View imported content', 'universal-wordpress-importer' ) ); ?></a></p>';
				}
				if (session.status !== 'done' && session.status !== 'aborted') {
					html += '<p><button type="button" class="button universal-importer-abort" data-session-id="' + escapeHtml(session.id) + '"><?php echo esc_js( __( 'Abort', 'universal-wordpress-importer' ) ); ?></button></p>';
				}
				html += '</div></section>';
				return html;
			}

			function renderChecklist(items, session) {
				if (!items.length) {
					return '';
				}
				var html = '<div class="universal-importer-stage-title"><?php echo esc_js( __( 'Import stages', 'universal-wordpress-importer' ) ); ?></div><ol class="universal-importer-checklist" aria-label="<?php echo esc_js( __( 'Import stages', 'universal-wordpress-importer' ) ); ?>">';
				items.forEach(function(item) {
					var state = item.state || 'pending';
					var itemHtml = '<li class="universal-importer-step" data-state="' + escapeHtml(state) + '"><span class="universal-importer-stage-index">' + escapeHtml(item.index || '') + '</span><span><span class="universal-importer-step-heading"><strong>' + escapeHtml(item.label || '') + '</strong><span class="universal-importer-step-state">' + escapeHtml(checklistStateLabel(state)) + '</span></span><span>' + escapeHtml(item.detail || '') + '</span>';
					if (item.note) {
						itemHtml += '<span class="universal-importer-stage-note">' + escapeHtml(item.note) + '</span>';
					}
					if (item.key === 'url_treatment') {
						itemHtml += renderStageDecision(session, 'url_treatment');
					}
					itemHtml += '</span></li>';
					html += itemHtml;
				});
				html += '</ol>';
				return html;
			}

			function urlDecisions(session) {
				return (session.pending_decisions || []).filter(function(decision) {
					return decision.key === 'confirm-first-party-domains';
				});
			}

			function remainingDecisions(session) {
				return (session.pending_decisions || []).filter(function(decision) {
					return decision.key !== 'confirm-first-party-domains';
				});
			}

			function renderStageDecision(session, stageKey) {
				var decisions = stageKey === 'url_treatment' ? urlDecisions(session) : [];
				if (!decisions.length) {
					return '';
				}

				return '<div class="universal-importer-stage-decision">' + renderDecisions(session, decisions) + '</div>';
			}

			function checklistStateLabel(state) {
				if (state === 'done') {
					return '<?php echo esc_js( __( 'Done', 'universal-wordpress-importer' ) ); ?>';
				}
				if (state === 'active') {
					return '<?php echo esc_js( __( 'In progress', 'universal-wordpress-importer' ) ); ?>';
				}
				if (state === 'blocked') {
					return '<?php echo esc_js( __( 'Needs attention', 'universal-wordpress-importer' ) ); ?>';
				}
				return '<?php echo esc_js( __( 'Not started', 'universal-wordpress-importer' ) ); ?>';
			}

			function renderActivityLog(events) {
				if (!events.length) {
					return '';
				}
				var html = '<div class="universal-importer-log"><strong><?php echo esc_js( __( 'Done so far', 'universal-wordpress-importer' ) ); ?></strong><ol>';
				events.forEach(function(event) {
					html += '<li>' + escapeHtml(event.message || '') + '</li>';
				});
				html += '</ol></div>';
				return html;
			}

			function renderRelationshipWarnings(warnings) {
				var html = '<div class="notice notice-warning inline universal-importer-relationship-warnings"><p><strong><?php echo esc_js( __( 'Relationship warnings', 'universal-wordpress-importer' ) ); ?></strong></p><ul>';
				warnings.forEach(function(warning) {
					html += '<li>' + escapeHtml(warning.summary) + '</li>';
				});
				html += '</ul></div>';
				return html;
			}

			function renderPipeline(session) {
				var sourceItems = session.source_items || { total: 0, statuses: {}, recent: [] };
				var documents = session.prepared_documents || { total: 0, recent: [] };
				var posts = session.posts || { persisted: 0 };
				var comments = session.comments || { persisted: 0 };
				var media = session.media || { total: 0, statuses: {}, recent: [] };
				var remoteBackoff = session.remote_backoff || { total: 0, recent: [] };
				var pdfDocuments = session.pdf_documents || { total: 0, recent: [] };
				var epubTocs = session.epub_tocs || { total: 0, recent: [] };
				var statuses = sourceItems.statuses || {};
				var mediaStatuses = media.statuses || {};
				var html = '<details class="universal-importer-pipeline"><summary><?php echo esc_js( __( 'Technical details', 'universal-wordpress-importer' ) ); ?></summary>';
				html += '<p><strong><?php echo esc_js( __( 'Source items:', 'universal-wordpress-importer' ) ); ?></strong> ' + sourceItems.total + ' total';
				html += ' <span>(' + (statuses.queued || 0) + ' queued, ' + (statuses.processing || 0) + ' processing, ' + (statuses.imported || 0) + ' imported, ' + (statuses.skipped || 0) + ' skipped, ' + (statuses.failed || 0) + ' failed)</span></p>';
				html += '<p><strong><?php echo esc_js( __( 'Prepared:', 'universal-wordpress-importer' ) ); ?></strong> ' + documents.total + ' <strong><?php echo esc_js( __( 'Drafts:', 'universal-wordpress-importer' ) ); ?></strong> ' + posts.persisted + ' <strong><?php echo esc_js( __( 'Comments:', 'universal-wordpress-importer' ) ); ?></strong> ' + comments.persisted + '</p>';
				html += '<p><strong><?php echo esc_js( __( 'Media:', 'universal-wordpress-importer' ) ); ?></strong> ' + media.total + ' <span>(' + (mediaStatuses.queued || 0) + ' queued, ' + (mediaStatuses.imported || 0) + ' imported, ' + (mediaStatuses.skipped || 0) + ' skipped, ' + (mediaStatuses.failed || 0) + ' failed)</span></p>';
				if (remoteBackoff.total) {
					html += '<p><strong><?php echo esc_js( __( 'Remote backoff:', 'universal-wordpress-importer' ) ); ?></strong> ' + remoteBackoff.total + ' active</p>';
				}
				if (epubTocs.total) {
					html += '<p><strong><?php echo esc_js( __( 'EPUB TOCs:', 'universal-wordpress-importer' ) ); ?></strong> ' + epubTocs.total + '</p>';
				}
				if (pdfDocuments.total) {
					html += '<p><strong><?php echo esc_js( __( 'PDF/OCR:', 'universal-wordpress-importer' ) ); ?></strong> ' + pdfDocuments.total + '</p>';
				}
				if (sourceItems.recent && sourceItems.recent.length) {
					html += '<h4><?php echo esc_js( __( 'Recent source items', 'universal-wordpress-importer' ) ); ?></h4><ul>';
					sourceItems.recent.forEach(function(item) {
						html += '<li><code>' + escapeHtml(item.status) + '</code> ' + escapeHtml(item.relative_path || item.source_uri) + ' <span>(' + escapeHtml(item.type) + ')</span>';
						if (item.metadata && item.metadata.error) {
							html += '<br><span class="description">' + escapeHtml(item.metadata.error) + '</span>';
						}
						html += '</li>';
					});
					html += '</ul>';
				}
				if (documents.recent && documents.recent.length) {
					html += '<h4><?php echo esc_js( __( 'Prepared documents', 'universal-wordpress-importer' ) ); ?></h4><ul>';
					documents.recent.forEach(function(document) {
						html += '<li><code>' + escapeHtml(document.format) + '</code> ' + escapeHtml(document.title) + ' <span>(' + document.block_count + ' blocks)</span></li>';
					});
					html += '</ul>';
				}
				if (remoteBackoff.recent && remoteBackoff.recent.length) {
					html += '<h4><?php echo esc_js( __( 'Remote backoff', 'universal-wordpress-importer' ) ); ?></h4><ul>';
					remoteBackoff.recent.forEach(function(backoff) {
						html += '<li>' + escapeHtml(backoff.source) + ': HTTP ' + escapeHtml(backoff.status_code) + ', retry in ' + escapeHtml(backoff.remaining_seconds) + 's';
						if (backoff.next_retry_at) {
							html += ' <span>(' + escapeHtml(backoff.next_retry_at) + ')</span>';
						}
						html += '<br><span class="description">' + escapeHtml(backoff.url) + '</span></li>';
					});
					html += '</ul>';
				}
				if (pdfDocuments.recent && pdfDocuments.recent.length) {
					html += '<h4><?php echo esc_js( __( 'PDF/OCR', 'universal-wordpress-importer' ) ); ?></h4><ul>';
					pdfDocuments.recent.forEach(function(pdf) {
						var engine = pdf.ocr_status ? pdf.engine + ' / ' + pdf.ocr_status : pdf.engine;
						html += '<li>' + escapeHtml(pdf.title) + ': <code>' + escapeHtml(pdf.status) + '</code> ' + escapeHtml(engine);
						if (pdf.message) {
							html += '<br><span class="description">' + escapeHtml(pdf.message) + '</span>';
						}
						if (pdf.hint) {
							html += '<br><span class="description">' + escapeHtml(pdf.hint) + '</span>';
						}
						html += '</li>';
					});
					html += '</ul>';
				}
				if (epubTocs.recent && epubTocs.recent.length) {
					html += '<h4><?php echo esc_js( __( 'EPUB TOCs', 'universal-wordpress-importer' ) ); ?></h4><ul>';
					epubTocs.recent.forEach(function(toc) {
						var location = toc.entry ? ' at ' + toc.entry : '';
						html += '<li>' + escapeHtml(toc.title) + ': ' + toc.count + ' ' + '<?php echo esc_js( __( 'entries from', 'universal-wordpress-importer' ) ); ?>' + ' ' + escapeHtml(toc.source) + escapeHtml(location);
						if (toc.entries && toc.entries.length) {
							html += '<ul>';
							toc.entries.forEach(function(entry) {
								var target = entry.target ? ' -> ' + entry.target : '';
								html += '<li>' + escapeHtml(entry.label) + escapeHtml(target) + '</li>';
							});
							html += '</ul>';
						}
						if (toc.error) {
							html += '<br><span class="description">' + escapeHtml(toc.error) + '</span>';
						}
						html += '</li>';
					});
					html += '</ul>';
				}
				if (media.recent && media.recent.length) {
					html += '<h4><?php echo esc_js( __( 'Media references', 'universal-wordpress-importer' ) ); ?></h4><ul>';
					media.recent.forEach(function(reference) {
						html += '<li><code>' + escapeHtml(reference.status) + '</code> ' + escapeHtml(reference.original_url) + ' <span>(' + escapeHtml(reference.media_type) + ')</span></li>';
					});
					html += '</ul>';
				}
				html += '</details>';
				return html;
			}

			function renderDecisions(session, decisions) {
				decisions = decisions || session.pending_decisions || [];
				var allUrlDecisions = decisions.length && decisions.every(function(decision) {
					return decision.key === 'confirm-first-party-domains';
				});
				var title = allUrlDecisions ? '<?php echo esc_js( __( 'URL treatment', 'universal-wordpress-importer' ) ); ?>' : '<?php echo esc_js( __( 'Import decision', 'universal-wordpress-importer' ) ); ?>';
				var html = '<div class="universal-importer-decisions"><h4>' + title + '</h4>';
				decisions.forEach(function(decision) {
					html += '<div class="universal-importer-decision" data-decision-key="' + escapeHtml(decision.key) + '">';
					if (decision.key === 'confirm-first-party-domains') {
						html += renderUrlDecision(session, decision);
					} else {
						html += '<p><strong>' + escapeHtml(decision.key) + ':</strong> ' + escapeHtml(decision.prompt) + '</p>';
						html += '<p><textarea class="large-text universal-importer-decision-answer" rows="6">' + escapeHtml(JSON.stringify(getDecisionAnswerTemplate(decision), null, 2)) + '</textarea></p>';
						html += '<p><button type="button" class="button universal-importer-resolve-decision" data-url-choice="selected" data-session-id="' + escapeHtml(session.id) + '" data-decision-key="' + escapeHtml(decision.key) + '"><?php echo esc_js( __( 'Resolve decision', 'universal-wordpress-importer' ) ); ?></button></p>';
					}
					html += '</div>';
				});
				html += '</div>';
				return html;
			}

			function renderUrlDecision(session, decision) {
				var domains = decision.options && decision.options.domains ? decision.options.domains : [];
				var examples = decision.options && decision.options.examples ? decision.options.examples : {};
				var html = '<p><strong><?php echo esc_js( __( 'Rewrite old-site URLs to this site?', 'universal-wordpress-importer' ) ); ?></strong></p>';
				html += '<p class="description"><?php echo esc_js( __( 'Selected hosts move to this site and keep the same paths. Unselected hosts stay unchanged.', 'universal-wordpress-importer' ) ); ?></p>';
				html += '<div class="universal-importer-domain-list">';
				domains.forEach(function(domain) {
					var domainExamples = examples[domain] || [];
					html += '<label><input type="checkbox" class="universal-importer-decision-domain" value="' + escapeHtml(domain) + '" checked><span><strong>' + escapeHtml(domain) + '</strong>';
					if (domainExamples.length) {
						html += '<span class="universal-importer-hint">' + escapeHtml(domainExamples[0]) + '</span>';
					}
					html += '</span></label>';
				});
				html += '</div>';
				html += '<p class="universal-importer-decision-actions"><button type="button" class="button button-primary universal-importer-resolve-decision" data-url-choice="selected" data-session-id="' + escapeHtml(session.id) + '" data-decision-key="' + escapeHtml(decision.key) + '"><?php echo esc_js( __( 'Rewrite selected domains', 'universal-wordpress-importer' ) ); ?></button> ';
				html += '<button type="button" class="button universal-importer-resolve-decision" data-url-choice="all" data-session-id="' + escapeHtml(session.id) + '" data-decision-key="' + escapeHtml(decision.key) + '"><?php echo esc_js( __( 'Yes, rewrite all', 'universal-wordpress-importer' ) ); ?></button> ';
				html += '<button type="button" class="button universal-importer-resolve-decision" data-url-choice="none" data-session-id="' + escapeHtml(session.id) + '" data-decision-key="' + escapeHtml(decision.key) + '"><?php echo esc_js( __( 'No, keep all URLs', 'universal-wordpress-importer' ) ); ?></button></p>';
				return html;
			}

			function getDecisionAnswerTemplate(decision) {
				if (decision.options && decision.options.answer_template) {
					return decision.options.answer_template;
				}

				return {};
			}

			function escapeHtml(value) {
				return String(value).replace(/[&<>"']/g, function(character) {
					return {
						'&': '&amp;',
						'<': '&lt;',
						'>': '&gt;',
						'"': '&quot;',
						"'": '&#039;'
					}[character];
				});
			}

			function isImportLocked(session) {
				return session
					&& session.id
					&& session.status !== 'done'
					&& session.status !== 'aborted'
					&& session.status !== 'failed'
					&& !canStartAnotherImport(session);
			}

			function canStartAnotherImport(session) {
				return session
					&& session.dashboard
					&& session.dashboard.needs_keepalive === false
					&& !!session.dashboard.attention_message
					&& !(session.pending_decisions && session.pending_decisions.length);
			}

			function primarySession() {
				var recentSessions = config.sessions || [];
				return recentSessions.length ? recentSessions[0] : null;
			}

			function syncPrimaryView(session) {
				var primary = session || primarySession();
				if (primary) {
					var wrapper = document.createElement('div');
					wrapper.innerHTML = renderSession(primary);
					sessions.innerHTML = '';
					sessions.appendChild(wrapper.firstElementChild);
					sessions.classList.remove('is-empty');
					if (emptyProgress) {
						emptyProgress.style.display = 'none';
					}
				} else {
					sessions.innerHTML = '';
					sessions.classList.add('is-empty');
					if (emptyProgress) {
						emptyProgress.style.display = '';
					}
				}

				if (form && form.classList) {
					if (form.classList.toggle) {
						form.classList.toggle('is-hidden', isImportLocked(primary));
					} else if (isImportLocked(primary)) {
						form.classList.add('is-hidden');
					} else {
						form.classList.remove('is-hidden');
					}
				}
			}

			function rememberSession(session) {
				config.sessions = [session];
				config.primary_session_id = session.id;
			}

			function upsertSession(session) {
				rememberSession(session);
				syncPrimaryView(session);
			}

			function sessionNeedsKeepalive(session) {
				return session
					&& session.id
					&& session.status !== 'done'
					&& session.status !== 'aborted'
					&& session.status !== 'failed'
					&& !(session.pending_decisions && session.pending_decisions.length)
					&& !(session.dashboard && session.dashboard.needs_keepalive === false);
			}

			function startKeepalive(sessionId) {
				activeSessionId = sessionId;
				if (!timer) {
					timer = window.setInterval(tick, 5000);
				}
			}

			function reattachActiveSession() {
				var session = primarySession();
				syncPrimaryView(session);
				if (sessionNeedsKeepalive(session)) {
					startKeepalive(session.id);
					tick();
				}
			}

			function tick() {
				if (!activeSessionId || keepaliveInFlight) {
					return;
				}
				keepaliveInFlight = true;
				request('<?php echo esc_js( self::AJAX_KEEPALIVE ); ?>', { session_id: activeSessionId }).then(function(data) {
					if (data.session) {
						upsertSession(data.session);
						if (!sessionNeedsKeepalive(data.session)) {
							window.clearInterval(timer);
							timer = null;
							activeSessionId = null;
							reattachActiveSession();
						}
					}
				}).catch(function(error) {
					showNotice(error.message, 'error');
				}).then(function() {
					keepaliveInFlight = false;
				});
			}

			form.addEventListener('submit', function(event) {
				event.preventDefault();
				submitImport();
			});

			function submitImport() {
				var data = new FormData(form);
				var action = '<?php echo esc_js( self::AJAX_CREATE ); ?>';
				// The URL input may have been detached from the form when the source
				// turn collapsed into a locked summary, so read its value directly
				// from the live JS reference rather than from FormData.
				var sourceUrl = ((sourceInput && sourceInput.value) || '').trim();
				var payload = {
					source: sourceUrl,
					confirmed_domains: data.get('confirmed_domains') || '',
					url_rewrite_mode: data.get('url_rewrite_mode') || 'ask',
					import_as_drafts: data.get('import_as_drafts') ? '1' : '',
					dry_run: data.get('dry_run') ? '1' : ''
				};

				if (browserFiles.length) {
					action = '<?php echo esc_js( self::AJAX_UPLOAD ); ?>';
					payload = new FormData();
					payload.set('confirmed_domains', data.get('confirmed_domains') || '');
					payload.set('url_rewrite_mode', data.get('url_rewrite_mode') || 'ask');
					payload.set('import_as_drafts', data.get('import_as_drafts') ? '1' : '');
					payload.set('dry_run', data.get('dry_run') ? '1' : '');
					browserFiles.forEach(function(file) {
						payload.append('files[]', file, file.name);
						payload.append('paths[]', filePath(file));
					});
				}

				request(action, payload).then(function(session) {
					upsertSession(session);
					showNotice('<?php echo esc_js( __( 'Import started.', 'universal-wordpress-importer' ) ); ?>', 'success');
					startKeepalive(session.id);
					tick();
				}).catch(function(error) {
					showNotice(error.message, 'error');
				});
			}

			filePicker.addEventListener('change', function() {
				setBrowserFiles(Array.prototype.slice.call(filePicker.files || []), '<?php echo esc_js( __( 'file selection', 'universal-wordpress-importer' ) ); ?>');
			});

			folderPicker.addEventListener('change', function() {
				setBrowserFiles(Array.prototype.slice.call(folderPicker.files || []), '<?php echo esc_js( __( 'folder selection', 'universal-wordpress-importer' ) ); ?>');
			});

			clearFilesButton.addEventListener('click', function() {
				filePicker.value = '';
				folderPicker.value = '';
				setBrowserFiles([], '');
			});

			function dragHasFiles(event) {
				if (!event.dataTransfer || !event.dataTransfer.types) {
					return false;
				}
				var types = Array.prototype.slice.call(event.dataTransfer.types);
				return types.indexOf('Files') !== -1;
			}

			function setDropActive(on) {
				if (dropzone && dropzone.classList) {
					if (on) {
						dropzone.classList.add('is-dragging');
					} else {
						dropzone.classList.remove('is-dragging');
					}
				}
				if (dropLine) {
					dropLine.textContent = on ? '<?php echo esc_js( __( 'Release to upload', 'universal-wordpress-importer' ) ); ?>' : originalDropText;
				}
			}

			var dragDepth = 0;
			dropzone.addEventListener('dragenter', function(event) {
				if (!dragHasFiles(event)) {
					return;
				}
				event.preventDefault();
				dragDepth++;
				setDropActive(true);
				focusMemo('upload');
			});
			dropzone.addEventListener('dragover', function(event) {
				if (!dragHasFiles(event)) {
					return;
				}
				event.preventDefault();
				if (event.dataTransfer) {
					event.dataTransfer.dropEffect = 'copy';
				}
			});
			dropzone.addEventListener('dragleave', function() {
				dragDepth = Math.max(0, dragDepth - 1);
				if (!dragDepth) {
					setDropActive(false);
				}
			});

			dropzone.addEventListener('drop', function(event) {
				event.preventDefault();
				dragDepth = 0;
				setDropActive(false);
				filesFromDrop(event.dataTransfer).then(function(files) {
					setBrowserFiles(files, '<?php echo esc_js( __( 'drop', 'universal-wordpress-importer' ) ); ?>');
				}).catch(function(error) {
					showNotice(error.message, 'error');
				});
			});

			sessions.addEventListener('click', function(event) {
				if (event.target.classList.contains('universal-importer-start-over')) {
					if (form && form.classList) {
						form.classList.remove('is-hidden');
					}
					if (form && form.scrollIntoView) {
						form.scrollIntoView({ behavior: 'smooth', block: 'start' });
					}
					if (sourceInput && sourceInput.focus) {
						sourceInput.focus({ preventScroll: true });
					}
					return;
				}
				if (!event.target.classList.contains('universal-importer-abort')) {
					if (!event.target.classList.contains('universal-importer-resolve-decision')) {
						return;
					}
					var button = event.target;
					var decision = button.closest('.universal-importer-decision');
					var data = {
						session_id: button.getAttribute('data-session-id'),
						decision_key: button.getAttribute('data-decision-key'),
						url_rewrite_choice: button.getAttribute('data-url-choice') || 'selected'
					};
					var domainCheckboxes = decision.querySelectorAll('.universal-importer-decision-domain');
					var answer = decision.querySelector('.universal-importer-decision-answer');
					if (domainCheckboxes.length) {
						var selectedDomains = [];
						Array.prototype.slice.call(domainCheckboxes).forEach(function(input) {
							if (data.url_rewrite_choice === 'all' || (data.url_rewrite_choice === 'selected' && input.checked)) {
								selectedDomains.push(input.value);
							}
						});
						data.confirmed_domains = data.url_rewrite_choice === 'none' ? '' : selectedDomains.join(', ');
					}
					if (answer) {
						data.answer = answer.value;
					}
					request('<?php echo esc_js( self::AJAX_DECIDE ); ?>', data).then(function(session) {
						upsertSession(session);
						showNotice('<?php echo esc_js( __( 'URL choice saved.', 'universal-wordpress-importer' ) ); ?>', 'success');
						startKeepalive(session.id);
						tick();
					}).catch(function(error) {
						showNotice(error.message, 'error');
					});
					return;
				}
				request('<?php echo esc_js( self::AJAX_ABORT ); ?>', { session_id: event.target.getAttribute('data-session-id') }).then(function(session) {
					upsertSession(session);
					showNotice('<?php echo esc_js( __( 'Import aborted.', 'universal-wordpress-importer' ) ); ?>', 'warning');
				}).catch(function(error) {
					showNotice(error.message, 'error');
				});
			});

			// ----- Progressive turn flow (Source -> Classify -> Configure -> Confirm -> Start) -----
			var turnsContainer = document.getElementById('universal-importer-turns');
			var sourceTurn = document.getElementById('universal-importer-turn-source');
			var sourceContinueButton = document.getElementById('universal-importer-source-continue');
			var sourceClearButton = document.getElementById('universal-importer-source-clear');
			var stateUrlMode = document.getElementById('universal-importer-state-url-mode');
			var stateDomains = document.getElementById('universal-importer-state-domains');
			var stateDry = document.getElementById('universal-importer-state-dry');
			var stateDrafts = document.getElementById('universal-importer-state-drafts');
			var stateSourceType = document.getElementById('universal-importer-state-source-type');

			var flowState = {
				turn: 'source',
				inferredType: '',
				inferredConsequence: '',
				typeOverride: '',
				urlMode: 'ask',
				domains: '',
				dry: false,
				drafts: false
			};
			var pastTurns = []; // [{ key: 'source'|'classify'|'configure', summary: '' }]

			function currentSourceLabel() {
				if (browserFiles.length) {
					var bytes = 0;
					for (var idx = 0; idx < browserFiles.length; idx++) {
						bytes += Number(browserFiles[idx].size || 0);
					}
					return 'Upload · ' + browserFiles.length + ' file' + (browserFiles.length === 1 ? '' : 's') + ' (' + formatBytes(bytes) + ')';
				}
				var url = (sourceInput.value || '').trim();
				return url ? 'URL · ' + url : '';
			}

			function formatBytes(bytes) {
				if (!bytes) { return '0 B'; }
				var units = ['B', 'KB', 'MB', 'GB'];
				var i = 0;
				var n = bytes;
				while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
				return (n < 10 && i > 0 ? n.toFixed(1) : Math.round(n)) + ' ' + units[i];
			}

			function inferSourceType() {
				if (browserFiles.length) {
					if (browserFiles.length > 1) {
						return { type: 'Local folder (uploaded)', consequence: browserFiles.length + ' files staged for local-folder import.' };
					}
					var name = filePath(browserFiles[0]).toLowerCase();
					if (name.slice(-4) === '.zip') {
						return { type: 'Zip archive (uploaded)', consequence: 'Archive will be unpacked and crawled.' };
					}
					if (name.slice(-5) === '.epub') {
						return { type: 'EPUB (uploaded)', consequence: 'Chapters parsed from the EPUB spine.' };
					}
					if (name.slice(-4) === '.pdf') {
						return { type: 'PDF (uploaded)', consequence: 'PDF text extracted page by page.' };
					}
					if (name.slice(-4) === '.xml' || name.slice(-4) === '.wxr') {
						return { type: 'WP export XML (WXR)', consequence: 'WXR posts and attachments will be read.' };
					}
					return { type: 'Local file (uploaded)', consequence: 'Single uploaded file will be imported.' };
				}
				var url = String((sourceInput.value || '')).trim();
				if (/^https?:\/\/github\.com\//i.test(url)) {
					return { type: 'GitHub repository', consequence: 'Repository tree fetched via sparse Git.' };
				}
				if (/\/wp-json\/?$/i.test(url)) {
					return { type: 'WordPress site URL', consequence: 'REST API used to pull pages and posts.' };
				}
				if (/\.opml(\?|$)/i.test(url)) {
					return { type: 'OPML feed list', consequence: 'Each listed feed will be fetched.' };
				}
				if (/sitemap.*\.xml(\?|$)/i.test(url)) {
					return { type: 'Sitemap.xml', consequence: 'Listed URLs will be crawled.' };
				}
				if (/\.(rss|atom)(\?|$)/i.test(url) || /\/feed\/?$/i.test(url)) {
					return { type: 'RSS / Atom / RDF feed', consequence: 'Feed entries will be ingested.' };
				}
				if (/\.xml(\?|$)/i.test(url)) {
					return { type: 'WP export XML (WXR)', consequence: 'WXR posts and attachments will be read.' };
				}
				if (url) {
					return { type: 'Remote HTML page', consequence: 'Page will be fetched and converted.' };
				}
				return { type: '', consequence: '' };
			}

			function buildSummaryBubble(key, speaker, summary) {
				var section = document.createElement('section');
				section.className = 'universal-importer-turn is-past';
				section.setAttribute('data-turn-key', key);
				var speakerRow = document.createElement('div');
				speakerRow.className = 'universal-importer-speaker';
				speakerRow.textContent = speaker;
				var editBtn = document.createElement('button');
				editBtn.type = 'button';
				editBtn.className = 'universal-importer-edit';
				editBtn.setAttribute('data-edit-key', key);
				editBtn.textContent = 'Edit';
				speakerRow.appendChild(editBtn);
				var body = document.createElement('div');
				body.className = 'universal-importer-body';
				body.textContent = summary;
				section.appendChild(speakerRow);
				section.appendChild(body);
				editBtn.addEventListener('click', function() { jumpBack(key); });
				return section;
			}

			function configureSummary() {
				var pieces = [];
				if (flowState.urlMode === 'ask') { pieces.push('Ask'); }
				else if (flowState.urlMode === 'preserve') { pieces.push('Keep unchanged'); }
				else { pieces.push('Rewrite: ' + (flowState.domains || '(none)')); }
				pieces.push(flowState.dry ? 'Dry run' : 'Real run');
				pieces.push(flowState.drafts ? 'Drafts' : 'Publish');
				return 'Configure · ' + pieces.join(' · ');
			}

			function lockSourceTurn() {
				var summary = currentSourceLabel();
				if (!summary) { return false; }
				var bubble = buildSummaryBubble('source', 'YOU', summary);
				turnsContainer.replaceChild(bubble, sourceTurn);
				pastTurns.push({ key: 'source', node: bubble });
				return true;
			}

			function lockClassifyTurn(node) {
				var summary = 'Type · ' + (flowState.typeOverride || flowState.inferredType);
				var bubble = buildSummaryBubble('classify', 'YOU', summary);
				turnsContainer.replaceChild(bubble, node);
				pastTurns.push({ key: 'classify', node: bubble });
			}

			function lockConfigureTurn(node) {
				var bubble = buildSummaryBubble('configure', 'YOU', configureSummary());
				turnsContainer.replaceChild(bubble, node);
				pastTurns.push({ key: 'configure', node: bubble });
			}

			function dropTurnsAfter(key) {
				var keys = ['source', 'classify', 'configure', 'confirm'];
				var idx = keys.indexOf(key);
				// Remove live (non-past) turns after the given key.
				var nodes = Array.prototype.slice.call(turnsContainer.children);
				nodes.forEach(function(node) {
					var nodeKey = node.getAttribute('data-turn-key');
					if (!nodeKey) { return; }
					if (keys.indexOf(nodeKey) > idx) { node.remove(); }
				});
				// Remove past turns after the given key.
				pastTurns = pastTurns.filter(function(entry) {
					if (keys.indexOf(entry.key) > idx) {
						entry.node.remove();
						return false;
					}
					return true;
				});
			}

			function jumpBack(key) {
				// Discard everything after the chosen key, then re-open that key's turn.
				dropTurnsAfter(key);
				// Remove the locked summary bubble for the chosen key (if any).
				pastTurns = pastTurns.filter(function(entry) {
					if (entry.key === key) { entry.node.remove(); return false; }
					return true;
				});
				flowState.turn = key;
				if (key === 'source') {
					turnsContainer.appendChild(sourceTurn);
					if (sourceInput.focus) { sourceInput.focus(); }
				} else if (key === 'classify') {
					renderClassifyTurn();
				} else if (key === 'configure') {
					renderConfigureTurn();
				}
			}

			function renderClassifyTurn() {
				var tpl = document.getElementById('universal-importer-template-classify');
				var node = tpl.content.firstElementChild.cloneNode(true);
				var line = node.querySelector('[data-classify-line]');
				var inferred = inferSourceType();
				if (!flowState.typeOverride) {
					flowState.inferredType = inferred.type;
					flowState.inferredConsequence = inferred.consequence;
				}
				var activeType = flowState.typeOverride || flowState.inferredType;
				var sentence = "I'll treat this as a " + activeType + ".";
				if (flowState.inferredConsequence) { sentence += ' ' + flowState.inferredConsequence; }
				line.textContent = sentence;
				var overrideBox = node.querySelector('[data-override]');
				overrideBox.querySelectorAll('button').forEach(function(btn) {
					if (btn.getAttribute('data-type') === activeType) { btn.classList.add('is-on'); }
					btn.addEventListener('click', function() {
						overrideBox.querySelectorAll('button').forEach(function(x) { x.classList.remove('is-on'); });
						btn.classList.add('is-on');
						flowState.typeOverride = btn.getAttribute('data-type') || '';
						line.textContent = "I'll treat this as a " + flowState.typeOverride + ".";
					});
				});
				node.querySelector('[data-action="toggle-override"]').addEventListener('click', function() {
					if (overrideBox.hasAttribute('hidden')) { overrideBox.removeAttribute('hidden'); }
					else { overrideBox.setAttribute('hidden', 'hidden'); }
				});
				node.querySelector('[data-action="continue"]').addEventListener('click', function() {
					stateSourceType.value = flowState.typeOverride || flowState.inferredType;
					lockClassifyTurn(node);
					flowState.turn = 'configure';
					renderConfigureTurn();
				});
				turnsContainer.appendChild(node);
			}

			function renderConfigureTurn() {
				var tpl = document.getElementById('universal-importer-template-configure');
				var node = tpl.content.firstElementChild.cloneNode(true);
				var radios = node.querySelectorAll('input[name="cfg_url"]');
				var domainsInput = node.querySelector('[data-domains]');
				var domainsErr = node.querySelector('[data-domain-err]');
				var dryToggle = node.querySelector('[data-toggle="dry"]');
				var draftsToggle = node.querySelector('[data-toggle="drafts"]');

				function syncRadioStyles() {
					radios.forEach(function(r) {
						var opt = r.closest('[data-url-option]');
						if (r.checked) { opt.classList.add('is-on'); } else { opt.classList.remove('is-on'); }
					});
					if (flowState.urlMode === 'rewrite') {
						domainsInput.removeAttribute('hidden');
					} else {
						domainsInput.setAttribute('hidden', 'hidden');
						domainsErr.setAttribute('hidden', 'hidden');
					}
				}
				radios.forEach(function(r) {
					if (r.value === flowState.urlMode) { r.checked = true; }
					r.addEventListener('change', function() {
						flowState.urlMode = r.value;
						syncRadioStyles();
					});
				});
				domainsInput.value = flowState.domains || '';
				domainsInput.addEventListener('input', function() { flowState.domains = domainsInput.value; domainsErr.setAttribute('hidden', 'hidden'); });
				if (flowState.dry) { dryToggle.classList.add('is-on'); dryToggle.setAttribute('aria-pressed', 'true'); }
				if (flowState.drafts) { draftsToggle.classList.add('is-on'); draftsToggle.setAttribute('aria-pressed', 'true'); }
				dryToggle.addEventListener('click', function() {
					flowState.dry = !flowState.dry;
					dryToggle.classList.toggle('is-on', flowState.dry);
					dryToggle.setAttribute('aria-pressed', flowState.dry ? 'true' : 'false');
				});
				draftsToggle.addEventListener('click', function() {
					flowState.drafts = !flowState.drafts;
					draftsToggle.classList.toggle('is-on', flowState.drafts);
					draftsToggle.setAttribute('aria-pressed', flowState.drafts ? 'true' : 'false');
				});
				syncRadioStyles();
				node.querySelector('[data-action="back"]').addEventListener('click', function() { jumpBack('classify'); });
				node.querySelector('[data-action="continue"]').addEventListener('click', function() {
					if (flowState.urlMode === 'rewrite') {
						var trimmed = (domainsInput.value || '').trim();
						if (!trimmed) { domainsErr.removeAttribute('hidden'); domainsInput.focus(); return; }
						flowState.domains = trimmed;
					}
					stateUrlMode.value = flowState.urlMode;
					stateDomains.value = flowState.domains || '';
					stateDry.value = flowState.dry ? '1' : '';
					stateDrafts.value = flowState.drafts ? '1' : '';
					lockConfigureTurn(node);
					flowState.turn = 'confirm';
					renderConfirmTurn();
				});
				turnsContainer.appendChild(node);
			}

			function renderConfirmTurn() {
				var tpl = document.getElementById('universal-importer-template-confirm');
				var node = tpl.content.firstElementChild.cloneNode(true);
				var headline = node.querySelector('[data-confirm-headline]');
				var detail = node.querySelector('[data-confirm-detail]');
				var typeLabel = flowState.typeOverride || flowState.inferredType || 'this source';
				headline.textContent = flowState.dry ? 'Ready to plan.' : 'Ready to import.';
				detail.textContent = currentSourceLabel() + ' · ' + typeLabel + ' · ' + (flowState.urlMode === 'ask' ? 'Ask on URLs' : flowState.urlMode === 'preserve' ? 'Keep URLs' : 'Rewrite ' + (flowState.domains || '')) + ' · ' + (flowState.dry ? 'Dry run' : 'Real run') + ' · ' + (flowState.drafts ? 'Drafts' : 'Publish');
				node.querySelector('[data-action="back"]').addEventListener('click', function() { jumpBack('configure'); });
				turnsContainer.appendChild(node);
			}

			if (sourceContinueButton) {
				sourceContinueButton.addEventListener('click', function() {
					if (!browserFiles.length && !((sourceInput.value || '').trim())) {
						sourceInput.focus();
						return;
					}
					if (!lockSourceTurn()) { return; }
					flowState.turn = 'classify';
					renderClassifyTurn();
				});
			}
			if (sourceClearButton) {
				sourceClearButton.addEventListener('click', function() {
					sourceInput.value = '';
					setBrowserFiles([], '');
					focusMemo('url');
					if (sourceInput.focus) { sourceInput.focus(); }
				});
			}
			sourceInput.addEventListener('keydown', function(event) {
				if (event.key === 'Enter') {
					event.preventDefault();
					if (sourceContinueButton) { sourceContinueButton.click(); }
				}
			});

			syncGithubPickerVisibility();
			reattachActiveSession();
		}());
		</script>
		<?php
	}

	/**
	 * Handles session creation AJAX requests.
	 *
	 * @return void
	 */
	public function ajax_create_session() {
		$this->assert_ajax_permission();

		try {
			$source            = $this->read_post_string( 'source' );
			$confirmed_domains = $this->parse_domain_list( $this->read_post_string( 'confirmed_domains' ) );
			$dry_run           = $this->read_post_bool( 'dry_run' );
			$url_rewrite_mode  = $this->read_post_string( 'url_rewrite_mode' );
			$import_as_drafts  = $this->read_post_bool( 'import_as_drafts' );

			wp_send_json_success( $this->create_import_session( $source, $confirmed_domains, $dry_run, $url_rewrite_mode, $import_as_drafts ) );
		} catch ( InvalidArgumentException $exception ) {
			wp_send_json_error( array( 'message' => $exception->getMessage() ), 400 );
		} catch ( RuntimeException $exception ) {
			wp_send_json_error( array( 'message' => $exception->getMessage() ), 500 );
		}
	}

	/**
	 * Handles browser file/folder upload AJAX requests.
	 *
	 * @return void
	 */
	public function ajax_upload_session() {
		$this->assert_ajax_permission();

		try {
			$files             = $this->read_uploaded_files( 'files' );
			$paths             = $this->read_post_string_array( 'paths' );
			$confirmed_domains = $this->parse_domain_list( $this->read_post_string( 'confirmed_domains' ) );
			$dry_run           = $this->read_post_bool( 'dry_run' );
			$url_rewrite_mode  = $this->read_post_string( 'url_rewrite_mode' );
			$import_as_drafts  = $this->read_post_bool( 'import_as_drafts' );

			wp_send_json_success( $this->create_import_session_from_uploaded_files( $files, $paths, $confirmed_domains, $dry_run, $url_rewrite_mode, $import_as_drafts ) );
		} catch ( InvalidArgumentException $exception ) {
			wp_send_json_error( array( 'message' => $exception->getMessage() ), 400 );
		} catch ( RuntimeException $exception ) {
			wp_send_json_error( array( 'message' => $exception->getMessage() ), 500 );
		}
	}

	/**
	 * Handles browser keepalive AJAX requests.
	 *
	 * @return void
	 */
	public function ajax_keepalive() {
		$this->assert_ajax_permission();

		try {
			$session_id = $this->read_post_string( 'session_id' );

			wp_send_json_success( $this->run_keepalive( $session_id ) );
		} catch ( InvalidArgumentException $exception ) {
			wp_send_json_error( array( 'message' => $exception->getMessage() ), 400 );
		} catch ( RuntimeException $exception ) {
			wp_send_json_error( array( 'message' => $exception->getMessage() ), 500 );
		}
	}

	/**
	 * Handles session abort AJAX requests.
	 *
	 * @return void
	 */
	public function ajax_abort_session() {
		$this->assert_ajax_permission();

		try {
			$session_id = $this->read_post_string( 'session_id' );

			wp_send_json_success( $this->abort_import_session( $session_id ) );
		} catch ( InvalidArgumentException $exception ) {
			wp_send_json_error( array( 'message' => $exception->getMessage() ), 400 );
		} catch ( RuntimeException $exception ) {
			wp_send_json_error( array( 'message' => $exception->getMessage() ), 500 );
		}
	}

	/**
	 * Handles decision resolution AJAX requests.
	 *
	 * @return void
	 */
	public function ajax_resolve_decision() {
		$this->assert_ajax_permission();

		try {
			$session_id   = $this->read_post_string( 'session_id' );
			$decision_key = $this->read_post_string( 'decision_key' );
			$answer       = $this->parse_decision_answer(
				$decision_key,
				$this->read_post_string( 'confirmed_domains' ),
				$this->read_post_string( 'answer' ),
				$this->read_post_string( 'url_rewrite_choice' )
			);

			wp_send_json_success( $this->resolve_import_decision( $session_id, $decision_key, $answer ) );
		} catch ( InvalidArgumentException $exception ) {
			wp_send_json_error( array( 'message' => $exception->getMessage() ), 400 );
		} catch ( RuntimeException $exception ) {
			wp_send_json_error( array( 'message' => $exception->getMessage() ), 500 );
		}
	}

	/**
	 * Handles GitHub directory picker AJAX requests.
	 *
	 * @return void
	 */
	public function ajax_github_directories() {
		$this->assert_ajax_permission();

		try {
			$source = $this->read_post_string( 'source' );

			wp_send_json_success( $this->list_github_directories( $source ) );
		} catch ( InvalidArgumentException $exception ) {
			wp_send_json_error( array( 'message' => $exception->getMessage() ), 400 );
		} catch ( RuntimeException $exception ) {
			wp_send_json_error( array( 'message' => $exception->getMessage() ), 500 );
		}
	}

	/**
	 * Returns the one session the admin page should focus on.
	 *
	 * @param array<int,array<string,mixed>> $sessions Recent session snapshots.
	 * @return array<string,mixed>|null
	 */
	private function primary_admin_session( array $sessions ) {
		foreach ( $sessions as $session ) {
			if ( is_array( $session ) ) {
				return $session;
			}
		}

		return null;
	}

	/**
	 * Returns whether a session should keep the start form out of view.
	 *
	 * @param array<string,mixed> $session Session snapshot.
	 * @return bool
	 */
	private function is_active_admin_session( array $session ) {
		$status = isset( $session['status'] ) ? (string) $session['status'] : '';

		return ImportSession::STATUS_DONE !== $status
			&& ImportSession::STATUS_ABORTED !== $status
			&& ImportSession::STATUS_FAILED !== $status
			&& ! $this->can_start_another_import( $session );
	}

	/**
	 * Returns whether the current import is stopped and the user should be able to start another one.
	 *
	 * @param array<string,mixed> $session Session snapshot.
	 * @return bool
	 */
	private function can_start_another_import( array $session ) {
		$dashboard = isset( $session['dashboard'] ) && is_array( $session['dashboard'] ) ? $session['dashboard'] : array();

		return isset( $dashboard['needs_keepalive'] )
			&& false === $dashboard['needs_keepalive']
			&& ! empty( $dashboard['attention_message'] )
			&& empty( $session['pending_decisions'] );
	}

	/**
	 * Renders a list of session snapshots.
	 *
	 * @param array<int,array<string,mixed>> $sessions Session snapshots.
	 * @return void
	 */
	private function render_session_list( array $sessions ) {
		if ( empty( $sessions ) ) {
			return;
		}

		foreach ( $sessions as $session ) {
			$dashboard      = isset( $session['dashboard'] ) && is_array( $session['dashboard'] ) ? $session['dashboard'] : array();
			$summary        = isset( $dashboard['summary'] ) && is_array( $dashboard['summary'] ) ? $dashboard['summary'] : array();
			$percentage     = isset( $dashboard['percentage'] ) ? max( 0, min( 100, (int) $dashboard['percentage'] ) ) : 0;
			$progress_class = ! empty( $dashboard['indeterminate'] ) ? ' universal-importer-progressbar is-indeterminate' : ' universal-importer-progressbar';
			$total          = empty( $summary['total'] ) ? '?' : (string) $summary['total'];
			$completed      = isset( $summary['completed'] ) ? (int) $summary['completed'] : 0;
			$errors         = isset( $summary['errors'] ) ? (int) $summary['errors'] : 0;
			$progress_note  = isset( $dashboard['progress_note'] ) ? (string) $dashboard['progress_note'] : '';
			$current_action = isset( $dashboard['current_action'] ) ? (string) $dashboard['current_action'] : __( 'Checking import state.', 'universal-wordpress-importer' );
			$display_status = empty( $dashboard['attention_message'] ) ? ( isset( $dashboard['status_label'] ) && '' !== (string) $dashboard['status_label'] ? (string) $dashboard['status_label'] : (string) $session['status'] ) : __( 'Needs attention', 'universal-wordpress-importer' );
			$card_class     = $this->is_active_admin_session( $session ) ? 'universal-importer-card is-importing' : 'universal-importer-card';
			$mode_label     = ! empty( $session['dry_run'] ) ? __( 'Dry run', 'universal-wordpress-importer' ) : ( isset( $session['post_status'] ) && 'draft' === $session['post_status'] ? __( 'Creates drafts', 'universal-wordpress-importer' ) : __( 'Publishes pages', 'universal-wordpress-importer' ) );
			?>
			<section class="<?php echo esc_attr( $card_class ); ?>" data-session-id="<?php echo esc_attr( $session['id'] ); ?>">
				<div class="universal-importer-card-header">
					<div>
						<h3 class="universal-importer-source-title"><?php echo esc_html( $session['source'] ); ?></h3>
						<p class="universal-importer-meta">
							<?php echo esc_html( $mode_label ); ?>
						</p>
					</div>
					<span class="universal-importer-status-pill"><?php echo esc_html( $display_status ); ?></span>
				</div>
				<div class="universal-importer-card-body">
					<p class="universal-importer-current-action"><?php echo esc_html( $current_action ); ?></p>
					<div class="<?php echo esc_attr( trim( $progress_class ) ); ?>" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( (string) $percentage ); ?>">
						<span style="width:<?php echo esc_attr( (string) $percentage ); ?>%"></span>
					</div>
					<p class="universal-importer-meta">
						<?php
						if ( '' !== $progress_note ) {
							echo esc_html( $progress_note );
						} else {
							echo esc_html(
								sprintf(
									/* translators: 1: percentage complete, 2: completed items, 3: total items. */
									__( '%1$d%% - %2$d / %3$s items complete', 'universal-wordpress-importer' ),
									$percentage,
									$completed,
									$total
								)
							);
						}
						if ( 0 < $errors ) {
							echo esc_html( sprintf( /* translators: %d: error count. */ __( ' - %d errors', 'universal-wordpress-importer' ), $errors ) );
						}
						?>
					</p>
					<?php if ( ! empty( $dashboard['attention_message'] ) ) : ?>
						<div class="notice notice-warning inline universal-importer-attention">
							<p><strong><?php esc_html_e( 'Needs attention', 'universal-wordpress-importer' ); ?></strong><br><?php echo esc_html( (string) $dashboard['attention_message'] ); ?></p>
							<?php if ( $this->can_start_another_import( $session ) ) : ?>
								<p class="universal-importer-attention-actions"><button type="button" class="button button-primary universal-importer-start-over"><?php esc_html_e( 'Start another import', 'universal-wordpress-importer' ); ?></button></p>
							<?php endif; ?>
						</div>
					<?php endif; ?>
					<?php $this->render_dashboard_checklist( isset( $dashboard['checklist'] ) && is_array( $dashboard['checklist'] ) ? $dashboard['checklist'] : array(), $session ); ?>
					<?php $this->render_relationship_warnings( $session ); ?>
					<?php $this->render_pending_decisions( $session, true ); ?>
					<?php $this->render_activity_log( isset( $dashboard['activity_log'] ) && is_array( $dashboard['activity_log'] ) ? $dashboard['activity_log'] : $session['recent_events'] ); ?>
					<?php $this->render_pipeline_details( $session ); ?>
					<?php if ( ImportSession::STATUS_DONE === $session['status'] && ! empty( $dashboard['imported_content_url'] ) ) : ?>
						<p><a class="button button-primary" href="<?php echo esc_url( $dashboard['imported_content_url'] ); ?>"><?php esc_html_e( 'View imported content', 'universal-wordpress-importer' ); ?></a></p>
					<?php endif; ?>
					<?php if ( ImportSession::STATUS_DONE !== $session['status'] && ImportSession::STATUS_ABORTED !== $session['status'] ) : ?>
						<p><button type="button" class="button universal-importer-abort" data-session-id="<?php echo esc_attr( $session['id'] ); ?>"><?php esc_html_e( 'Abort', 'universal-wordpress-importer' ); ?></button></p>
					<?php endif; ?>
				</div>
			</section>
			<?php
		}
	}

	/**
	 * Renders the compact high-level import checklist.
	 *
	 * @param array<int,array<string,string>> $items   Checklist items.
	 * @param array<string,mixed>             $session Session snapshot.
	 * @return void
	 */
	private function render_dashboard_checklist( array $items, array $session ) {
		if ( empty( $items ) ) {
			return;
		}

		?>
		<div class="universal-importer-stage-title"><?php esc_html_e( 'Import stages', 'universal-wordpress-importer' ); ?></div>
		<ol class="universal-importer-checklist" aria-label="<?php echo esc_attr__( 'Import stages', 'universal-wordpress-importer' ); ?>">
			<?php foreach ( $items as $item ) : ?>
				<?php $state = isset( $item['state'] ) ? (string) $item['state'] : 'pending'; ?>
				<li class="universal-importer-step" data-state="<?php echo esc_attr( isset( $item['state'] ) ? $item['state'] : 'pending' ); ?>">
					<span class="universal-importer-stage-index"><?php echo esc_html( isset( $item['index'] ) ? $item['index'] : '' ); ?></span>
					<span>
						<span class="universal-importer-step-heading">
							<strong><?php echo esc_html( isset( $item['label'] ) ? $item['label'] : '' ); ?></strong>
							<span class="universal-importer-step-state"><?php echo esc_html( $this->dashboard_stage_status_label( $state ) ); ?></span>
						</span>
						<span><?php echo esc_html( isset( $item['detail'] ) ? $item['detail'] : '' ); ?></span>
						<?php if ( ! empty( $item['note'] ) ) : ?>
							<span class="universal-importer-stage-note"><?php echo esc_html( (string) $item['note'] ); ?></span>
						<?php endif; ?>
						<?php if ( isset( $item['key'] ) && 'url_treatment' === $item['key'] ) : ?>
							<?php $this->render_stage_decision( $session, 'url_treatment' ); ?>
						<?php endif; ?>
					</span>
				</li>
			<?php endforeach; ?>
		</ol>
		<?php
	}

	/**
	 * Returns the short visible label for a dashboard stage state.
	 *
	 * @param string $state Stage state.
	 * @return string
	 */
	private function dashboard_stage_status_label( $state ) {
		if ( 'done' === $state ) {
			return __( 'Done', 'universal-wordpress-importer' );
		}

		if ( 'active' === $state ) {
			return __( 'In progress', 'universal-wordpress-importer' );
		}

		if ( 'blocked' === $state ) {
			return __( 'Needs attention', 'universal-wordpress-importer' );
		}

		return __( 'Not started', 'universal-wordpress-importer' );
	}

	/**
	 * Renders the compact activity log.
	 *
	 * @param array<int,array<string,string>> $events Activity events.
	 * @return void
	 */
	private function render_activity_log( array $events ) {
		if ( empty( $events ) ) {
			return;
		}

		?>
		<div class="universal-importer-log">
			<strong><?php esc_html_e( 'Done so far', 'universal-wordpress-importer' ); ?></strong>
			<ol>
				<?php foreach ( $events as $event ) : ?>
					<li><?php echo esc_html( isset( $event['message'] ) ? $event['message'] : '' ); ?></li>
				<?php endforeach; ?>
			</ol>
		</div>
		<?php
	}

	/**
	 * Renders a pending decision inside the matching checklist stage.
	 *
	 * @param array<string,mixed> $session Session snapshot.
	 * @param string              $stage_key Checklist stage key.
	 * @return void
	 */
	private function render_stage_decision( array $session, $stage_key ) {
		if ( 'url_treatment' !== $stage_key || empty( $session['pending_decisions'] ) ) {
			return;
		}

		$url_decisions = array();
		foreach ( $session['pending_decisions'] as $decision ) {
			if ( isset( $decision['key'] ) && 'confirm-first-party-domains' === $decision['key'] ) {
				$url_decisions[] = $decision;
			}
		}

		if ( empty( $url_decisions ) ) {
			return;
		}

		?>
		<div class="universal-importer-stage-decision">
			<?php $this->render_pending_decisions( $session, false, $url_decisions ); ?>
		</div>
		<?php
	}

	/**
	 * Renders pipeline details for one session snapshot.
	 *
	 * @param array<string,mixed> $session Session snapshot.
	 * @return void
	 */
	private function render_pipeline_details( array $session ) {
		$source_items = $session['source_items'];
		$statuses     = $source_items['statuses'];
		$documents    = $session['prepared_documents'];
		$posts        = $session['posts'];
		$comments     = $session['comments'];
		$media        = $session['media'];
		$backoff      = $session['remote_backoff'];
		$pdfs         = $session['pdf_documents'];
		$epub_tocs    = $session['epub_tocs'];
		$media_counts = $media['statuses'];
		?>
		<details class="universal-importer-pipeline">
			<summary><?php esc_html_e( 'Technical details', 'universal-wordpress-importer' ); ?></summary>
			<p>
				<strong><?php esc_html_e( 'Source items:', 'universal-wordpress-importer' ); ?></strong>
				<?php echo esc_html( (string) $source_items['total'] ); ?>
				<span>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: queued items, 2: processing items, 3: discovered items, 4: imported items, 5: skipped items, 6: failed items. */
							__( '(%1$d queued, %2$d processing, %3$d discovered, %4$d imported, %5$d skipped, %6$d failed)', 'universal-wordpress-importer' ),
							$statuses['queued'],
							$statuses['processing'],
							$statuses['discovered'],
							$statuses['imported'],
							$statuses['skipped'],
							$statuses['failed']
						)
					);
					?>
				</span>
			</p>
			<p>
				<strong><?php esc_html_e( 'Prepared documents:', 'universal-wordpress-importer' ); ?></strong>
				<?php echo esc_html( (string) $documents['total'] ); ?>
				<strong><?php esc_html_e( 'Persisted posts:', 'universal-wordpress-importer' ); ?></strong>
				<?php echo esc_html( (string) $posts['persisted'] ); ?>
				<strong><?php esc_html_e( 'Imported comments:', 'universal-wordpress-importer' ); ?></strong>
				<?php echo esc_html( (string) $comments['persisted'] ); ?>
			</p>
			<p>
				<strong><?php esc_html_e( 'Media references:', 'universal-wordpress-importer' ); ?></strong>
				<?php echo esc_html( (string) $media['total'] ); ?>
				<span>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: queued references, 2: imported references, 3: skipped references, 4: failed references. */
							__( '(%1$d queued, %2$d imported, %3$d skipped, %4$d failed)', 'universal-wordpress-importer' ),
							$media_counts['queued'],
							$media_counts['imported'],
							$media_counts['skipped'],
							$media_counts['failed']
						)
					);
					?>
					</span>
				</p>
			<?php if ( ! empty( $backoff['total'] ) ) : ?>
				<p>
					<strong><?php esc_html_e( 'Remote backoff:', 'universal-wordpress-importer' ); ?></strong>
					<?php echo esc_html( sprintf( /* translators: %d: active remote backoff count. */ __( '%d active', 'universal-wordpress-importer' ), $backoff['total'] ) ); ?>
				</p>
			<?php endif; ?>
			<?php if ( ! empty( $epub_tocs['total'] ) ) : ?>
				<p>
					<strong><?php esc_html_e( 'EPUB TOCs:', 'universal-wordpress-importer' ); ?></strong>
					<?php echo esc_html( (string) $epub_tocs['total'] ); ?>
				</p>
			<?php endif; ?>
			<?php if ( ! empty( $pdfs['total'] ) ) : ?>
				<p>
					<strong><?php esc_html_e( 'PDF/OCR:', 'universal-wordpress-importer' ); ?></strong>
					<?php echo esc_html( (string) $pdfs['total'] ); ?>
				</p>
			<?php endif; ?>
			<?php if ( ! empty( $source_items['recent'] ) ) : ?>
				<h4><?php esc_html_e( 'Recent source items', 'universal-wordpress-importer' ); ?></h4>
				<ul>
					<?php foreach ( $source_items['recent'] as $item ) : ?>
						<li>
							<code><?php echo esc_html( $item['status'] ); ?></code>
							<?php echo esc_html( '' === $item['relative_path'] ? $item['source_uri'] : $item['relative_path'] ); ?>
							<span>(<?php echo esc_html( $item['type'] ); ?>)</span>
							<?php if ( ! empty( $item['metadata']['error'] ) ) : ?>
								<br><span class="description"><?php echo esc_html( (string) $item['metadata']['error'] ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( ! empty( $documents['recent'] ) ) : ?>
				<h4><?php esc_html_e( 'Prepared documents', 'universal-wordpress-importer' ); ?></h4>
				<ul>
					<?php foreach ( $documents['recent'] as $document ) : ?>
						<li><code><?php echo esc_html( $document['format'] ); ?></code> <?php echo esc_html( $document['title'] ); ?> <span><?php echo esc_html( sprintf( '(%d blocks)', $document['block_count'] ) ); ?></span></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( ! empty( $backoff['recent'] ) ) : ?>
				<h4><?php esc_html_e( 'Remote backoff', 'universal-wordpress-importer' ); ?></h4>
				<ul>
					<?php foreach ( $backoff['recent'] as $entry ) : ?>
						<li>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: source label, 2: HTTP status code, 3: seconds until retry, 4: retry timestamp. */
									__( '%1$s: HTTP %2$d, retry in %3$d seconds%4$s', 'universal-wordpress-importer' ),
									$entry['source'],
									$entry['status_code'],
									$entry['remaining_seconds'],
									'' === $entry['next_retry_at'] ? '' : ' at ' . $entry['next_retry_at']
								)
							);
							?>
							<br><span class="description"><?php echo esc_html( $entry['url'] ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( ! empty( $pdfs['recent'] ) ) : ?>
				<h4><?php esc_html_e( 'PDF/OCR', 'universal-wordpress-importer' ); ?></h4>
				<ul>
					<?php foreach ( $pdfs['recent'] as $pdf ) : ?>
						<li>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: PDF title, 2: source item status, 3: text extraction engine and OCR status. */
									__( '%1$s: %2$s, %3$s', 'universal-wordpress-importer' ),
									$pdf['title'],
									$pdf['status'],
									'' === $pdf['ocr_status'] ? $pdf['engine'] : $pdf['engine'] . ' / ' . $pdf['ocr_status']
								)
							);
							?>
							<?php if ( '' !== $pdf['message'] ) : ?>
								<br><span class="description"><?php echo esc_html( $pdf['message'] ); ?></span>
							<?php endif; ?>
							<?php if ( '' !== $pdf['hint'] ) : ?>
								<br><span class="description"><?php echo esc_html( $pdf['hint'] ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( ! empty( $epub_tocs['recent'] ) ) : ?>
				<h4><?php esc_html_e( 'EPUB TOCs', 'universal-wordpress-importer' ); ?></h4>
				<ul>
					<?php foreach ( $epub_tocs['recent'] as $toc ) : ?>
						<li>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: EPUB title, 2: TOC entry count, 3: TOC source type, 4: TOC archive path. */
									__( '%1$s: %2$d entries from %3$s%4$s', 'universal-wordpress-importer' ),
									$toc['title'],
									$toc['count'],
									$toc['source'],
									'' === $toc['entry'] ? '' : ' at ' . $toc['entry']
								)
							);
							?>
							<?php if ( ! empty( $toc['entries'] ) ) : ?>
								<ul>
									<?php foreach ( $toc['entries'] as $entry ) : ?>
										<li><?php echo esc_html( $entry['label'] . ( '' === $entry['target'] ? '' : ' -> ' . $entry['target'] ) ); ?></li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
							<?php if ( '' !== $toc['error'] ) : ?>
								<br><span class="description"><?php echo esc_html( $toc['error'] ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( ! empty( $media['recent'] ) ) : ?>
				<h4><?php esc_html_e( 'Media references', 'universal-wordpress-importer' ); ?></h4>
				<ul>
					<?php foreach ( $media['recent'] as $reference ) : ?>
						<li><code><?php echo esc_html( $reference['status'] ); ?></code> <?php echo esc_html( $reference['original_url'] ); ?> <span>(<?php echo esc_html( $reference['media_type'] ); ?>)</span></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</details>
		<?php
	}

	/**
	 * Renders relationship warnings for one session snapshot.
	 *
	 * @param array<string,mixed> $session Session snapshot.
	 * @return void
	 */
	private function render_relationship_warnings( array $session ) {
		if ( empty( $session['relationship_warnings'] ) ) {
			return;
		}

		?>
		<div class="notice notice-warning inline universal-importer-relationship-warnings">
			<p><strong><?php esc_html_e( 'Relationship warnings', 'universal-wordpress-importer' ); ?></strong></p>
			<ul>
				<?php foreach ( $session['relationship_warnings'] as $warning ) : ?>
					<li><?php echo esc_html( $warning['summary'] ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Renders pending decision controls for one session snapshot.
	 *
	 * @param array<string,mixed>            $session               Session snapshot.
	 * @param bool                           $exclude_url_decisions Whether to omit URL treatment decisions.
	 * @param array<int,array<string,mixed>> $decisions             Decision subset to render.
	 * @return void
	 */
	private function render_pending_decisions( array $session, $exclude_url_decisions = false, array $decisions = null ) {
		$decisions = null === $decisions ? (array) $session['pending_decisions'] : $decisions;

		if ( $exclude_url_decisions ) {
			$decisions = array_values(
				array_filter(
					$decisions,
					function ( $decision ) {
						return ! isset( $decision['key'] ) || 'confirm-first-party-domains' !== $decision['key'];
					}
				)
			);
		}

		if ( empty( $decisions ) ) {
			return;
		}

		$all_url_decisions = true;
		foreach ( $decisions as $decision ) {
			if ( ! isset( $decision['key'] ) || 'confirm-first-party-domains' !== $decision['key'] ) {
				$all_url_decisions = false;
				break;
			}
		}

		?>
		<div class="universal-importer-decisions">
			<h4><?php echo esc_html( $all_url_decisions ? __( 'URL treatment', 'universal-wordpress-importer' ) : __( 'Import decision', 'universal-wordpress-importer' ) ); ?></h4>
			<?php foreach ( $decisions as $decision ) : ?>
				<div class="universal-importer-decision" data-decision-key="<?php echo esc_attr( $decision['key'] ); ?>">
					<?php if ( 'confirm-first-party-domains' === $decision['key'] ) : ?>
						<?php
						$domains  = isset( $decision['options']['domains'] ) && is_array( $decision['options']['domains'] ) ? $decision['options']['domains'] : array();
						$examples = isset( $decision['options']['examples'] ) && is_array( $decision['options']['examples'] ) ? $decision['options']['examples'] : array();
						?>
						<p><strong><?php esc_html_e( 'Rewrite old-site URLs to this site?', 'universal-wordpress-importer' ); ?></strong></p>
						<p class="description"><?php esc_html_e( 'Selected hosts move to this site and keep the same paths. Unselected hosts stay unchanged.', 'universal-wordpress-importer' ); ?></p>
						<div class="universal-importer-domain-list">
							<?php foreach ( $domains as $domain ) : ?>
								<?php
								$domain          = (string) $domain;
								$domain_examples = isset( $examples[ $domain ] ) && is_array( $examples[ $domain ] ) ? $examples[ $domain ] : array();
								?>
								<label>
									<input type="checkbox" class="universal-importer-decision-domain" value="<?php echo esc_attr( $domain ); ?>" checked>
									<span>
										<strong><?php echo esc_html( $domain ); ?></strong>
										<?php if ( ! empty( $domain_examples ) ) : ?>
											<span class="universal-importer-hint"><?php echo esc_html( (string) $domain_examples[0] ); ?></span>
										<?php endif; ?>
									</span>
								</label>
							<?php endforeach; ?>
						</div>
						<p class="universal-importer-decision-actions">
							<button type="button" class="button button-primary universal-importer-resolve-decision" data-url-choice="selected" data-session-id="<?php echo esc_attr( $session['id'] ); ?>" data-decision-key="<?php echo esc_attr( $decision['key'] ); ?>"><?php esc_html_e( 'Rewrite selected domains', 'universal-wordpress-importer' ); ?></button>
							<button type="button" class="button universal-importer-resolve-decision" data-url-choice="all" data-session-id="<?php echo esc_attr( $session['id'] ); ?>" data-decision-key="<?php echo esc_attr( $decision['key'] ); ?>"><?php esc_html_e( 'Yes, rewrite all', 'universal-wordpress-importer' ); ?></button>
							<button type="button" class="button universal-importer-resolve-decision" data-url-choice="none" data-session-id="<?php echo esc_attr( $session['id'] ); ?>" data-decision-key="<?php echo esc_attr( $decision['key'] ); ?>"><?php esc_html_e( 'No, keep all URLs', 'universal-wordpress-importer' ); ?></button>
						</p>
					<?php else : ?>
						<p><strong><?php echo esc_html( $decision['key'] ); ?>:</strong> <?php echo esc_html( $decision['prompt'] ); ?></p>
						<p><textarea class="large-text universal-importer-decision-answer" rows="6"><?php echo esc_textarea( $this->decision_answer_template_json( $decision ) ); ?></textarea></p>
						<p><button type="button" class="button universal-importer-resolve-decision" data-session-id="<?php echo esc_attr( $session['id'] ); ?>" data-decision-key="<?php echo esc_attr( $decision['key'] ); ?>"><?php esc_html_e( 'Resolve decision', 'universal-wordpress-importer' ); ?></button></p>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Verifies AJAX nonce and capability.
	 *
	 * @return void
	 */
	private function assert_ajax_permission() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to manage imports.', 'universal-wordpress-importer' ) ), 403 );
		}
	}

	/**
	 * Reads a sanitized scalar string from POST data after nonce verification.
	 *
	 * @param string $key POST key.
	 * @return string
	 */
	private function read_post_string( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by the AJAX handler before this helper is called.
		if ( ! isset( $_POST[ $key ] ) || ! is_scalar( $_POST[ $key ] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by the AJAX handler before this helper is called.
		return sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) );
	}

	/**
	 * Reads a boolean-ish POST flag after nonce verification.
	 *
	 * @param string $key POST key.
	 * @return bool
	 */
	private function read_post_bool( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by the AJAX handler before this helper is called.
		return ! empty( $_POST[ $key ] );
	}

	/**
	 * Reads an array of POST strings after nonce verification.
	 *
	 * @param string $key POST key.
	 * @return array<int,string>
	 */
	private function read_post_string_array( $key ) {
		$values = array();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Verified before this helper is called; upload paths are normalized segment-by-segment before use.
		if ( isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Verified before this helper is called; fallback unslashing is used only outside WordPress.
			$values = function_exists( 'wp_unslash' ) ? wp_unslash( $_POST[ $key ] ) : $_POST[ $key ];
		}

		return array_map(
			function ( $value ) {
				return (string) $value;
			},
			array_values( $values )
		);
	}

	/**
	 * Reads uploaded file rows after nonce verification.
	 *
	 * @param string $key Files key.
	 * @return array<int,array<string,mixed>>
	 */
	private function read_uploaded_files( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by the AJAX handler before this helper is called.
		if ( empty( $_FILES[ $key ] ) || ! is_array( $_FILES[ $key ] ) ) {
			return array();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Verified before this helper is called; file rows are validated before staging.
		$files = $_FILES[ $key ];

		if ( ! isset( $files['name'] ) || ! is_array( $files['name'] ) ) {
			return array( $files );
		}

		$normalized = array();

		foreach ( array_keys( $files['name'] ) as $index ) {
			$normalized[] = array(
				'name'     => isset( $files['name'][ $index ] ) ? (string) $files['name'][ $index ] : '',
				'type'     => isset( $files['type'][ $index ] ) ? (string) $files['type'][ $index ] : '',
				'tmp_name' => isset( $files['tmp_name'][ $index ] ) ? (string) $files['tmp_name'][ $index ] : '',
				'error'    => isset( $files['error'][ $index ] ) ? (int) $files['error'][ $index ] : UPLOAD_ERR_NO_FILE,
				'size'     => isset( $files['size'][ $index ] ) ? (int) $files['size'][ $index ] : 0,
			);
		}

		return $normalized;
	}

	/**
	 * Loads the upload cache directory.
	 *
	 * @return ImportCacheDirectory
	 */
	private function get_cache_directory() {
		if ( null === $this->cache_directory ) {
			$this->cache_directory = ImportCacheDirectory::from_environment();
		}

		return $this->cache_directory;
	}

	/**
	 * Loads the remote content fetcher.
	 *
	 * @return ImportRemoteContentFetcherInterface
	 */
	private function get_content_fetcher() {
		if ( null === $this->content_fetcher ) {
			$this->content_fetcher = new WordPressRemoteContentFetcher();
		}

		return $this->content_fetcher;
	}

	/**
	 * Fetches the default branch for a GitHub repository.
	 *
	 * @param ImportRemoteContentFetcherInterface $fetcher Remote JSON fetcher.
	 * @param array{owner:string,name:string}     $repo    Repository data.
	 * @return string
	 * @throws RuntimeException When the repository response is malformed.
	 */
	private function fetch_github_default_branch( ImportRemoteContentFetcherInterface $fetcher, array $repo ) {
		$metadata = $fetcher->fetch_json( GitHubRepositorySourceUrl::repository_api_url( $repo ) );
		$branch   = isset( $metadata['default_branch'] ) ? GitHubRepositorySourceUrl::normalize_ref( (string) $metadata['default_branch'] ) : '';

		if ( '' === $branch || 'HEAD' === strtoupper( $branch ) ) {
			throw new RuntimeException( 'GitHub repository response did not include a default branch.' );
		}

		return $branch;
	}

	/**
	 * Builds an admin directory picker snapshot from a GitHub tree response.
	 *
	 * @param array{owner:string,name:string,ref:string,source_path:string,source_url:string,requested_ref?:string} $repo Repository data.
	 * @param array<string,mixed>                                                                                   $tree GitHub tree response.
	 * @return array<string,mixed>
	 */
	private function github_directory_snapshot( array $repo, array $tree ) {
		$paths = array( '' => true );

		foreach ( isset( $tree['tree'] ) && is_array( $tree['tree'] ) ? $tree['tree'] : array() as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['type'], $entry['path'] ) || 'tree' !== (string) $entry['type'] ) {
				continue;
			}

			$path = GitHubRepositorySourceUrl::normalize_source_path( (string) $entry['path'] );
			if ( '' !== $path ) {
				$paths[ $path ] = true;
			}
		}

		$path_list = array_keys( $paths );
		usort(
			$path_list,
			function ( $a, $b ) {
				if ( '' === $a ) {
					return -1;
				}
				if ( '' === $b ) {
					return 1;
				}

				return strcmp( $a, $b );
			}
		);

		$selected_path = GitHubRepositorySourceUrl::normalize_source_path( $repo['source_path'] );
		if ( ! isset( $paths[ $selected_path ] ) ) {
			$selected_path = '';
		}

		$directories = array();
		foreach ( $path_list as $path ) {
			$directories[] = array(
				'path'       => $path,
				'name'       => '' === $path ? $repo['name'] : basename( $path ),
				'depth'      => '' === $path ? 0 : substr_count( $path, '/' ) + 1,
				'source_url' => GitHubRepositorySourceUrl::source_url( $repo, $path ),
			);
		}

		return array(
			'owner'               => $repo['owner'],
			'repository'          => $repo['name'],
			'ref'                 => $repo['ref'],
			'requested_ref'       => isset( $repo['requested_ref'] ) ? $repo['requested_ref'] : $repo['ref'],
			'selected_path'       => $selected_path,
			'selected_source_url' => GitHubRepositorySourceUrl::source_url( $repo, $selected_path ),
			'source_url'          => GitHubRepositorySourceUrl::source_url( $repo, '' ),
			'truncated'           => ! empty( $tree['truncated'] ),
			'directories'         => $directories,
		);
	}

	/**
	 * Normalizes a browser-provided relative file path into safe path segments.
	 *
	 * @param string $path Browser relative path.
	 * @return array<int,string>
	 * @throws InvalidArgumentException When the path is unsafe.
	 */
	private function normalize_uploaded_relative_path( $path ) {
		$path     = str_replace( '\\', '/', trim( (string) $path ) );
		$path     = ltrim( $path, '/' );
		$segments = array();

		foreach ( explode( '/', $path ) as $segment ) {
			$segment = trim( $segment );

			if ( '' === $segment || '.' === $segment ) {
				continue;
			}

			if ( '..' === $segment ) {
				throw new InvalidArgumentException( 'Browser upload paths cannot contain parent directory segments.' );
			}

			$segment = function_exists( 'sanitize_file_name' ) ? sanitize_file_name( $segment ) : preg_replace( '/[^A-Za-z0-9._-]+/', '-', $segment );
			$segment = trim( is_string( $segment ) ? $segment : '', '.-' );

			if ( '' === $segment ) {
				throw new InvalidArgumentException( 'Browser upload contains an unsafe empty path segment.' );
			}

			$segments[] = $segment;
		}

		if ( empty( $segments ) ) {
			throw new InvalidArgumentException( 'Browser upload contains a file without a usable relative path.' );
		}

		return $segments;
	}

	/**
	 * Records an initial GitHub queue event with enough context for the dashboard activity log.
	 *
	 * @param ImportSession $session New import session.
	 * @return void
	 */
	private function record_initial_github_queue_event( ImportSession $session ) {
		$repo = GitHubRepositorySourceUrl::parse( $session->get_source() );

		if ( null === $repo ) {
			return;
		}

		$this->get_store()->record_event(
			$session->get_id(),
			new ImportProgressEvent(
				ImportProgressEvent::LEVEL_INFO,
				'github.fetch_queued',
				'GitHub repository fetch queued; file count will appear after discovery.',
				array(
					'github_owner'         => $repo['owner'],
					'github_repository'    => $repo['name'],
					'github_ref'           => $repo['ref'],
					'github_requested_ref' => $repo['ref'],
					'github_source_path'   => $repo['source_path'],
				)
			)
		);
	}

	/**
	 * Stores an initial URL rewrite decision when the operator chooses one.
	 *
	 * @param ImportSessionId   $session_id        Session id.
	 * @param array<int,string> $confirmed_domains Confirmed domains.
	 * @param string            $mode              URL rewrite mode.
	 * @return void
	 * @throws InvalidArgumentException When the mode needs domains but none were supplied.
	 */
	private function save_initial_url_rewrite_preference( ImportSessionId $session_id, array $confirmed_domains, $mode ) {
		$mode              = $this->normalize_url_rewrite_mode( $mode );
		$confirmed_domains = $this->normalize_domain_list( $confirmed_domains );

		if ( 'ask' === $mode && empty( $confirmed_domains ) ) {
			return;
		}

		if ( 'rewrite' === $mode && empty( $confirmed_domains ) ) {
			throw new InvalidArgumentException( 'Enter at least one source domain to rewrite, or choose to be asked later.' );
		}

		$this->get_store()->save_decision(
			$session_id,
			ImportDecision::pending(
				'confirm-first-party-domains',
				'Choose whether imported absolute URLs should be rewritten to this site.',
				array( 'domains' => $confirmed_domains )
			)->resolve( array( 'confirmed_domains' => 'preserve' === $mode ? array() : $confirmed_domains ) )
		);
	}

	/**
	 * Stores the initial imported post status preference.
	 *
	 * @param ImportSessionId $session_id        Session id.
	 * @param bool            $import_as_drafts Whether imported posts should remain drafts.
	 * @return void
	 */
	private function save_initial_post_status_preference( ImportSessionId $session_id, $import_as_drafts ) {
		$this->get_store()->save_decision(
			$session_id,
			ImportDecision::pending(
				ImportPostPersister::POST_STATUS_DECISION_KEY,
				'Choose whether imported pages should be published or saved as drafts.',
				array()
			)->resolve( array( 'post_status' => $import_as_drafts ? 'draft' : 'publish' ) )
		);
	}

	/**
	 * Normalizes an admin URL rewrite mode.
	 *
	 * @param string $mode Raw mode.
	 * @return string
	 */
	private function normalize_url_rewrite_mode( $mode ) {
		$mode = strtolower( trim( (string) $mode ) );

		if ( in_array( $mode, array( 'ask', 'rewrite', 'preserve' ), true ) ) {
			return $mode;
		}

		return 'ask';
	}

	/**
	 * Moves or copies one uploaded browser file into the importer cache.
	 *
	 * @param string $tmp_name Temporary upload path.
	 * @param string $target   Target cache path.
	 * @return void
	 * @throws RuntimeException When the file cannot be staged.
	 */
	private function stage_uploaded_file( $tmp_name, $target ) {
		$tmp_name = (string) $tmp_name;
		$target   = (string) $target;

		if ( '' === $tmp_name || ! is_file( $tmp_name ) ) {
			throw new RuntimeException( 'Browser upload source file is missing.' );
		}

		$this->get_cache_directory()->ensure_parent_directory( $target );

		if ( is_file( $target ) ) {
			throw new RuntimeException( 'Browser upload target already exists.' );
		}

		if ( is_uploaded_file( $tmp_name ) ) {
			if ( move_uploaded_file( $tmp_name, $target ) ) {
				return;
			}

			throw new RuntimeException( 'Unable to move browser upload into importer cache.' );
		}

		if ( 'cli' === PHP_SAPI && copy( $tmp_name, $target ) ) {
			return;
		}

		throw new RuntimeException( 'Browser upload was not accepted as a valid uploaded file.' );
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
	 * Creates the shared continuation runner.
	 *
	 * @return object
	 */
	private function create_runner() {
		if ( null !== $this->runner_factory ) {
			$factory = $this->runner_factory;

			return $factory( $this->get_store() );
		}

		return new ImportRunner( $this->get_store(), 'admin' );
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
	 * Converts a decision to an admin snapshot.
	 *
	 * @param ImportDecision $decision Decision.
	 * @return array<string,mixed>
	 */
	private function decision_to_snapshot( ImportDecision $decision ) {
		return array(
			'key'     => $decision->get_key(),
			'prompt'  => $decision->get_prompt(),
			'options' => $decision->get_options(),
		);
	}

	/**
	 * Returns a JSON answer template for generic decision controls.
	 *
	 * @param array<string,mixed> $decision Decision snapshot.
	 * @return string
	 */
	private function decision_answer_template_json( array $decision ) {
		$options  = isset( $decision['options'] ) && is_array( $decision['options'] ) ? $decision['options'] : array();
		$template = isset( $options['answer_template'] ) && is_array( $options['answer_template'] ) ? $options['answer_template'] : array();

		if ( function_exists( 'wp_json_encode' ) ) {
			$json = wp_json_encode( $template, JSON_PRETTY_PRINT );
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Unit tests may render without WordPress loaded.
			$json = json_encode( $template, JSON_PRETTY_PRINT );
		}

		return is_string( $json ) ? $json : '{}';
	}

	/**
	 * Converts an event to an admin snapshot.
	 *
	 * @param ImportProgressEvent $event Event.
	 * @return array<string,mixed>
	 */
	private function event_to_snapshot( ImportProgressEvent $event ) {
		return $event->to_array();
	}

	/**
	 * Builds compact dashboard state for the admin progress card.
	 *
	 * @param array<string,mixed> $session Session snapshot.
	 * @return array<string,mixed>
	 */
	private function build_dashboard_snapshot( array $session ) {
		$progress      = isset( $session['progress'] ) && is_array( $session['progress'] ) ? $session['progress'] : array();
		$source_items  = isset( $session['source_items'] ) && is_array( $session['source_items'] ) ? $session['source_items'] : array();
		$source_counts = isset( $source_items['statuses'] ) && is_array( $source_items['statuses'] ) ? $source_items['statuses'] : array();
		$total         = isset( $progress['total'] ) && null !== $progress['total'] ? (int) $progress['total'] : (int) ( isset( $source_items['total'] ) ? $source_items['total'] : 0 );
		$completed     = isset( $progress['completed'] ) ? (int) $progress['completed'] : 0;
		$errors        = isset( $progress['errors'] ) ? (int) $progress['errors'] : 0;
		$percentage    = 0;

		if ( 0 < $total ) {
			$percentage = min( 100, (int) floor( ( $completed / $total ) * 100 ) );
		}

		if ( ImportSession::STATUS_DONE === $session['status'] ) {
			$percentage = 100;
		}

		return array(
			'percentage'        => $percentage,
			'indeterminate'     => $this->dashboard_progress_is_indeterminate( $session ),
			'status_label'      => $this->dashboard_status_label( $session ),
			'progress_note'     => $this->dashboard_progress_note( $session ),
			'current_action'    => $this->dashboard_current_action( $session ),
			'attention_message' => $this->dashboard_attention_message( $session ),
			'needs_keepalive'   => $this->dashboard_needs_keepalive( $session ),
			'summary'           => array(
				'total'     => $total,
				'completed' => $completed,
				'errors'    => $errors,
			),
			'checklist'         => $this->dashboard_checklist( $session, $source_counts ),
			'activity_log'      => $this->dashboard_activity_log( $session ),
		);
	}

	/**
	 * Returns whether progress is active without a known item total yet.
	 *
	 * @param array<string,mixed> $session Session snapshot.
	 * @return bool
	 */
	private function dashboard_progress_is_indeterminate( array $session ) {
		if ( ImportSession::STATUS_RUNNING !== $session['status'] && ! $this->is_pending_github_discovery( $session ) ) {
			return false;
		}

		if ( ! empty( $session['github_git']['active'] ) ) {
			return true;
		}

		$source_total = isset( $session['source_items']['total'] ) ? (int) $session['source_items']['total'] : 0;

		return 0 === $source_total;
	}

	/**
	 * Builds the status pill label for the progress card.
	 *
	 * @param array<string,mixed> $session Session snapshot.
	 * @return string
	 */
	private function dashboard_status_label( array $session ) {
		if ( $this->is_pending_github_discovery( $session ) ) {
			return $this->admin_text( 'Starting' );
		}

		if ( ! empty( $session['github_git']['active'] ) ) {
			return $this->admin_text( 'Fetching' );
		}

		return isset( $session['status'] ) ? (string) $session['status'] : '';
	}

	/**
	 * Builds concise progress text for phases without a known item total.
	 *
	 * @param array<string,mixed> $session Session snapshot.
	 * @return string
	 */
	private function dashboard_progress_note( array $session ) {
		if ( $this->is_pending_github_discovery( $session ) ) {
			return $this->admin_text( 'File count appears after GitHub repository discovery.' );
		}

		if ( ! empty( $session['github_git']['active'] ) ) {
			return $this->admin_text( 'Fetching repository files; file count appears after discovery.' );
		}

		return '';
	}

	/**
	 * Translates admin snapshot strings when WordPress is loaded.
	 *
	 * @param string $text English text.
	 * @return string
	 */
	private function admin_text( $text ) {
		if ( function_exists( '__' ) ) {
			// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dashboard strings are centralized through this fallback for unit tests without WordPress loaded.
			return __( $text, 'universal-wordpress-importer' );
		}

		return $text;
	}

	/**
	 * Builds a plain-English current action.
	 *
	 * @param array<string,mixed> $session Session snapshot.
	 * @return string
	 */
	private function dashboard_current_action( array $session ) {
		if ( ImportSession::STATUS_DONE === $session['status'] ) {
			return $this->admin_text( 'Import complete.' );
		}

		if ( ImportSession::STATUS_ABORTED === $session['status'] ) {
			return $this->admin_text( 'Import aborted.' );
		}

		if ( ImportSession::STATUS_FAILED === $session['status'] ) {
			return $this->admin_text( 'Import failed.' );
		}

		if ( ! empty( $session['pending_decisions'] ) ) {
			$first_decision = $session['pending_decisions'][0];

			if ( isset( $first_decision['key'] ) && 'confirm-first-party-domains' === $first_decision['key'] ) {
				return $this->admin_text( 'Choose URL treatment to continue.' );
			}

			return $this->admin_text( 'Choose how to continue.' );
		}

		if ( ! empty( $session['remote_backoff']['total'] ) ) {
			return $this->admin_text( 'Waiting for the remote source.' );
		}

		if ( ! empty( $session['github_git']['active'] ) ) {
			return $this->admin_text( 'Fetching repository files with sparse Git.' );
		}

		$source_statuses = isset( $session['source_items']['statuses'] ) && is_array( $session['source_items']['statuses'] ) ? $session['source_items']['statuses'] : array();
		$media_statuses  = isset( $session['media']['statuses'] ) && is_array( $session['media']['statuses'] ) ? $session['media']['statuses'] : array();
		$source_total    = isset( $session['source_items']['total'] ) ? (int) $session['source_items']['total'] : 0;
		$document_total  = isset( $session['prepared_documents']['total'] ) ? (int) $session['prepared_documents']['total'] : 0;
		$post_total      = isset( $session['posts']['persisted'] ) ? (int) $session['posts']['persisted'] : 0;

		if ( ! empty( $source_statuses['failed'] ) ) {
			return sprintf(
				/* translators: %d: failed source item count. */
				$this->admin_text( '%d source item needs attention.' ),
				(int) $source_statuses['failed']
			);
		}

		if ( ! empty( $media_statuses['failed'] ) ) {
			return sprintf(
				/* translators: %d: failed media reference count. */
				$this->admin_text( '%d media item needs attention.' ),
				(int) $media_statuses['failed']
			);
		}

		if ( $this->is_pending_github_discovery( $session ) ) {
			return $this->admin_text( 'Queued to fetch GitHub repository files.' );
		}

		if ( ImportSession::STATUS_PENDING === $session['status'] && 0 === $source_total ) {
			return $this->admin_text( 'Queued.' );
		}

		if ( ImportSession::STATUS_RUNNING === $session['status'] && 0 === $source_total ) {
			return $this->admin_text( 'Reading the source.' );
		}

		if ( ! empty( $source_statuses['queued'] ) || ! empty( $source_statuses['processing'] ) ) {
			return $this->admin_text( 'Reading the source.' );
		}

		if ( ! empty( $source_statuses['discovered'] ) ) {
			return $this->admin_text( 'Preparing content.' );
		}

		if ( ! empty( $media_statuses['queued'] ) ) {
			return $this->admin_text( 'Importing media.' );
		}

		if ( 0 < $document_total && $post_total < $document_total ) {
			return $this->admin_text( 'Writing pages.' );
		}

		if ( ! empty( $session['relationship_warnings'] ) ) {
			return $this->admin_text( 'Reviewing relationships.' );
		}

		return $this->admin_text( 'Checking import state.' );
	}

	/**
	 * Builds the current operator attention message.
	 *
	 * @param array<string,mixed> $session Session snapshot.
	 * @return string
	 */
	private function dashboard_attention_message( array $session ) {
		$source_statuses = isset( $session['source_items']['statuses'] ) && is_array( $session['source_items']['statuses'] ) ? $session['source_items']['statuses'] : array();
		$media_statuses  = isset( $session['media']['statuses'] ) && is_array( $session['media']['statuses'] ) ? $session['media']['statuses'] : array();

		if ( ! empty( $session['pending_decisions'] ) ) {
			return $this->admin_text( 'Answer the prompt below to continue the import.' );
		}

		if ( ! empty( $source_statuses['failed'] ) ) {
			return sprintf(
				/* translators: %d: failed source item count. */
				$this->admin_text( '%d source item failed. The importer will not continue until the source problem is corrected and a new import is started.' ),
				(int) $source_statuses['failed']
			);
		}

		if ( ! empty( $media_statuses['failed'] ) ) {
			return sprintf(
				/* translators: %d: failed media item count. */
				$this->admin_text( '%d media item failed. Drafts may still exist, but media references need review.' ),
				(int) $media_statuses['failed']
			);
		}

		return '';
	}

	/**
	 * Returns whether browser keepalive polling should continue.
	 *
	 * @param array<string,mixed> $session Session snapshot.
	 * @return bool
	 */
	private function dashboard_needs_keepalive( array $session ) {
		if ( ImportSession::STATUS_PENDING === $session['status'] ) {
			return true;
		}

		if ( ImportSession::STATUS_RUNNING !== $session['status'] ) {
			return false;
		}

		if ( '' !== $this->dashboard_attention_message( $session ) ) {
			return false;
		}

		if ( ! empty( $session['pending_decisions'] ) ) {
			return false;
		}

		$source_statuses = isset( $session['source_items']['statuses'] ) && is_array( $session['source_items']['statuses'] ) ? $session['source_items']['statuses'] : array();
		$media_statuses  = isset( $session['media']['statuses'] ) && is_array( $session['media']['statuses'] ) ? $session['media']['statuses'] : array();
		$source_total    = isset( $session['source_items']['total'] ) ? (int) $session['source_items']['total'] : 0;
		$document_total  = isset( $session['prepared_documents']['total'] ) ? (int) $session['prepared_documents']['total'] : 0;
		$post_total      = isset( $session['posts']['persisted'] ) ? (int) $session['posts']['persisted'] : 0;

		if ( 0 === $source_total ) {
			return true;
		}

		if ( ! empty( $source_statuses['queued'] ) || ! empty( $source_statuses['processing'] ) || ! empty( $source_statuses['discovered'] ) ) {
			return true;
		}

		if ( ! empty( $media_statuses['queued'] ) ) {
			return true;
		}

		return 0 < $document_total && $post_total < $document_total;
	}

	/**
	 * Builds a short visible note for the first failed source item.
	 *
	 * @param array<string,mixed> $session Session snapshot.
	 * @return string
	 */
	private function dashboard_source_failure_note( array $session ) {
		if ( empty( $session['source_items']['recent'] ) || ! is_array( $session['source_items']['recent'] ) ) {
			return '';
		}

		foreach ( $session['source_items']['recent'] as $item ) {
			if ( ! is_array( $item ) || ImportSourceItem::STATUS_FAILED !== ( isset( $item['status'] ) ? (string) $item['status'] : '' ) ) {
				continue;
			}

			$label = isset( $item['relative_path'] ) && '' !== (string) $item['relative_path'] ? (string) $item['relative_path'] : ( isset( $item['source_uri'] ) ? (string) $item['source_uri'] : '' );
			$error = isset( $item['metadata']['error'] ) ? trim( (string) $item['metadata']['error'] ) : '';

			if ( '' === $label ) {
				return $error;
			}

			if ( '' === $error ) {
				return $label;
			}

			return $label . ': ' . $error;
		}

		return '';
	}

	/**
	 * Builds high-level progress stages.
	 *
	 * @param array<string,mixed> $session       Session snapshot.
	 * @param array<string,int>   $source_counts Source item status counts.
	 * @return array<int,array<string,string>>
	 */
	private function dashboard_checklist( array $session, array $source_counts ) {
		$queued_or_processing = (int) ( isset( $source_counts['queued'] ) ? $source_counts['queued'] : 0 ) + (int) ( isset( $source_counts['processing'] ) ? $source_counts['processing'] : 0 );
		$source_discovered    = (int) ( isset( $source_counts['discovered'] ) ? $source_counts['discovered'] : 0 );
		$source_total         = (int) ( isset( $session['source_items']['total'] ) ? $session['source_items']['total'] : 0 );
		$source_failed        = (int) ( isset( $source_counts['failed'] ) ? $source_counts['failed'] : 0 );
		$document_total       = (int) ( isset( $session['prepared_documents']['total'] ) ? $session['prepared_documents']['total'] : 0 );
		$post_total           = (int) ( isset( $session['posts']['persisted'] ) ? $session['posts']['persisted'] : 0 );
		$is_dry_run           = ! empty( $session['dry_run'] );
		$media_statuses       = isset( $session['media']['statuses'] ) && is_array( $session['media']['statuses'] ) ? $session['media']['statuses'] : array();
		$media_total          = (int) ( isset( $session['media']['total'] ) ? $session['media']['total'] : 0 );
		$media_open           = (int) ( isset( $media_statuses['queued'] ) ? $media_statuses['queued'] : 0 );
		$media_failed         = (int) ( isset( $media_statuses['failed'] ) ? $media_statuses['failed'] : 0 );
		$has_decision         = ! empty( $session['pending_decisions'] );
		$is_done              = ImportSession::STATUS_DONE === $session['status'];
		$github_git_active    = ! empty( $session['github_git']['active'] );

		$stages = array(
			array(
				'index'  => '1',
				'key'    => 'read_source',
				'label'  => $this->admin_text( 'Read source' ),
				'detail' => $this->admin_text( 'Not started.' ),
				'state'  => 'pending',
			),
			array(
				'index'  => '2',
				'key'    => 'prepare_content',
				'label'  => $this->admin_text( 'Prepare content' ),
				'detail' => $this->admin_text( 'Not started.' ),
				'state'  => 'pending',
			),
			array(
				'index'  => '3',
				'key'    => 'url_treatment',
				'label'  => $this->admin_text( 'URL treatment' ),
				'detail' => $this->admin_text( 'Not started.' ),
				'state'  => 'pending',
			),
			array(
				'index'  => '4',
				'key'    => 'import_media',
				'label'  => $this->admin_text( 'Import media' ),
				'detail' => $this->admin_text( 'Not started.' ),
				'state'  => 'pending',
			),
			array(
				'index'  => '5',
				'key'    => 'write_pages',
				'label'  => $this->admin_text( 'Write pages' ),
				'detail' => $this->admin_text( 'Not started.' ),
				'state'  => 'pending',
			),
			array(
				'index'  => '6',
				'key'    => 'finish',
				'label'  => $this->admin_text( 'Finish' ),
				'detail' => $this->admin_text( 'Not started.' ),
				'state'  => 'pending',
			),
		);

		if ( 0 < $source_failed ) {
			$stages[0]['detail'] = sprintf( $this->admin_text( '%d source item failed.' ), $source_failed );
			$stages[0]['note']   = $this->dashboard_source_failure_note( $session );
			$stages[0]['state']  = 'blocked';
			return $stages;
		}

		if ( 0 === $source_total ) {
			if ( ! $is_done ) {
				if ( $github_git_active ) {
					$stages[0]['detail'] = $this->admin_text( 'Fetching repository files with sparse Git.' );
				} elseif ( $this->is_pending_github_discovery( $session ) ) {
					$stages[0]['detail'] = $this->admin_text( 'Waiting to fetch repository files from GitHub.' );
				} else {
					$stages[0]['detail'] = $this->admin_text( 'Queued.' );
				}
				$stages[0]['state'] = 'active';
				return $stages;
			}

			$stages[0]['detail'] = $this->admin_text( 'No source items found.' );
			$stages[0]['state']  = 'done';
		} else {
			if ( 0 < $queued_or_processing ) {
				$stages[0]['detail'] = sprintf( $this->admin_text( '%d source items found.' ), $source_total );
				$stages[0]['state']  = 'active';
				return $stages;
			}

			$stages[0]['detail'] = sprintf( $this->admin_text( '%d source items found.' ), $source_total );
			$stages[0]['state']  = 'done';
		}

		if ( 0 < $source_discovered ) {
			$stages[1]['detail'] = sprintf( $this->admin_text( 'Preparing %d item.' ), $source_discovered );
			$stages[1]['state']  = 'active';
			return $stages;
		}

		if ( 0 === $document_total && ! $is_done ) {
			$stages[1]['detail'] = $this->admin_text( 'Looking for importable content.' );
			$stages[1]['state']  = 'active';
			return $stages;
		}

		$stages[1]['detail'] = 0 < $document_total ? sprintf( $this->admin_text( '%d documents ready.' ), $document_total ) : $this->admin_text( 'No importable documents found.' );
		$stages[1]['state']  = 'done';

		if ( $has_decision ) {
			$stages[2]['detail'] = $this->admin_text( 'Choose how old URLs should be handled.' );
			$stages[2]['state']  = 'blocked';
			return $stages;
		}

		$stages[2]['detail'] = $this->admin_text( 'URL choice is set.' );
		$stages[2]['state']  = 'done';

		if ( 0 < $media_failed ) {
			$stages[3]['detail'] = sprintf( $this->admin_text( '%d media item failed.' ), $media_failed );
			$stages[3]['state']  = 'blocked';
			return $stages;
		}

		if ( 0 < $media_open ) {
			$stages[3]['detail'] = sprintf( $this->admin_text( '%d media items queued.' ), $media_open );
			$stages[3]['state']  = 'active';
			return $stages;
		}

		$stages[3]['detail'] = 0 < $media_total ? sprintf( $this->admin_text( '%d media items imported.' ), $media_total ) : $this->admin_text( 'No media found.' );
		$stages[3]['state']  = 'done';

		if ( $is_dry_run ) {
			$stages[4]['detail'] = $this->admin_text( 'Dry run: no pages written.' );
			$stages[4]['state']  = 'done';
		} elseif ( 0 < $document_total && $post_total < $document_total ) {
			$stages[4]['detail'] = sprintf( $this->admin_text( '%1$d of %2$d pages written.' ), $post_total, $document_total );
			$stages[4]['state']  = 'active';
			return $stages;
		} else {
			$stages[4]['detail'] = 0 < $document_total ? sprintf( $this->admin_text( '%1$d of %2$d pages written.' ), $post_total, $document_total ) : $this->admin_text( 'No pages to write.' );
			$stages[4]['state']  = 'done';
		}

		$stages[5]['detail'] = $is_done ? $this->admin_text( 'Complete.' ) : $this->admin_text( 'Final checks.' );
		$stages[5]['state']  = $is_done ? 'done' : 'active';

		return $stages;
	}

	/**
	 * Returns whether this snapshot is waiting for first GitHub repository discovery.
	 *
	 * @param array<string,mixed> $session Session snapshot.
	 * @return bool
	 */
	private function is_pending_github_discovery( array $session ) {
		if ( ImportSession::STATUS_PENDING !== ( isset( $session['status'] ) ? (string) $session['status'] : '' ) ) {
			return false;
		}

		$source_total = isset( $session['source_items']['total'] ) ? (int) $session['source_items']['total'] : 0;

		return 0 === $source_total && isset( $session['source'] ) && null !== GitHubRepositorySourceUrl::parse( (string) $session['source'] );
	}

	/**
	 * Builds a recent activity log for the progress card.
	 *
	 * @param array<string,mixed> $session Session snapshot.
	 * @return array<int,array<string,string>>
	 */
	private function dashboard_activity_log( array $session ) {
		$entries = array();

		foreach ( $session['recent_events'] as $event ) {
			$type      = isset( $event['type'] ) ? (string) $event['type'] : '';
			$message   = isset( $event['message'] ) ? (string) $event['message'] : '';
			$timestamp = isset( $event['created_at'] ) ? (string) $event['created_at'] : '';

			if ( '' === $message ) {
				continue;
			}

			$entries[] = array(
				'type'       => $type,
				'message'    => $message,
				'created_at' => $timestamp,
			);
		}

		return $entries;
	}

	/**
	 * Builds source item counts and recent item detail for an admin snapshot.
	 *
	 * @param ImportSessionId $id Session id.
	 * @return array<string,mixed>
	 */
	private function get_source_item_snapshot( ImportSessionId $id ) {
		$store    = $this->get_store();
		$statuses = array(
			ImportSourceItem::STATUS_QUEUED,
			ImportSourceItem::STATUS_PROCESSING,
			ImportSourceItem::STATUS_DISCOVERED,
			ImportSourceItem::STATUS_IMPORTED,
			ImportSourceItem::STATUS_SKIPPED,
			ImportSourceItem::STATUS_FAILED,
		);
		$counts   = array_fill_keys( $statuses, 0 );
		$recent   = array();
		$items    = $store->list_source_items_by_statuses( $id, $statuses, 1500 );

		foreach ( $items as $item ) {
			if ( $this->is_internal_source_item( $item ) ) {
				continue;
			}

			$status = $item->get_status();
			if ( isset( $counts[ $status ] ) ) {
				++$counts[ $status ];
			}

			if ( count( $recent ) < 8 ) {
				$recent[] = $this->source_item_to_snapshot( $item );
			}
		}

		return array(
			'total'    => array_sum( $counts ),
			'statuses' => $counts,
			'recent'   => $recent,
		);
	}

	/**
	 * Returns whether a source item is internal traversal bookkeeping.
	 *
	 * @param ImportSourceItem $item Source item.
	 * @return bool
	 */
	private function is_internal_source_item( ImportSourceItem $item ) {
		return 0 === strpos( $item->get_key(), 'github-git:' );
	}

	/**
	 * Builds active GitHub sparse Git traversal details for the dashboard.
	 *
	 * @param ImportSessionId $id Session id.
	 * @return array<string,mixed>
	 */
	private function get_github_git_snapshot( ImportSessionId $id ) {
		$items = $this->get_store()->list_source_items_by_statuses(
			$id,
			array(
				ImportSourceItem::STATUS_PROCESSING,
				ImportSourceItem::STATUS_SKIPPED,
				ImportSourceItem::STATUS_FAILED,
			),
			50
		);

		$snapshot = array(
			'active' => false,
			'recent' => array(),
		);

		foreach ( $items as $item ) {
			$metadata = $item->get_metadata();
			if ( empty( $metadata['github_git_status'] ) ) {
				continue;
			}

			$entry = array(
				'status'      => (string) $metadata['github_git_status'],
				'ref'         => isset( $metadata['github_ref'] ) ? (string) $metadata['github_ref'] : '',
				'source_path' => isset( $metadata['github_source_path'] ) ? (string) $metadata['github_source_path'] : '',
				'detail'      => isset( $metadata['github_git_status_detail'] ) ? (string) $metadata['github_git_status_detail'] : '',
				'started_at'  => isset( $metadata['github_git_started_at'] ) ? (string) $metadata['github_git_started_at'] : '',
			);

			if ( ImportSourceItem::STATUS_PROCESSING === $item->get_status() && 'pulling' === $entry['status'] ) {
				$snapshot['active']  = true;
				$snapshot['current'] = $entry;
			}

			if ( count( $snapshot['recent'] ) < 5 ) {
				$snapshot['recent'][] = $entry;
			}
		}

		return $snapshot;
	}

	/**
	 * Converts a source item to an admin snapshot.
	 *
	 * @param ImportSourceItem $item Source item.
	 * @return array<string,mixed>
	 */
	private function source_item_to_snapshot( ImportSourceItem $item ) {
		return array(
			'key'           => $item->get_key(),
			'parent_key'    => $item->get_parent_key(),
			'source_uri'    => $item->get_source_uri(),
			'relative_path' => $item->get_relative_path(),
			'type'          => $item->get_type(),
			'status'        => $item->get_status(),
			'metadata'      => $item->get_metadata(),
		);
	}

	/**
	 * Converts a prepared document to an admin snapshot.
	 *
	 * @param ImportPreparedDocument $document Prepared document.
	 * @return array<string,mixed>
	 */
	private function prepared_document_to_snapshot( ImportPreparedDocument $document ) {
		return array(
			'source_item_key' => $document->get_source_item_key(),
			'format'          => $document->get_format(),
			'title'           => $document->get_title(),
			'block_count'     => $document->get_block_count(),
			'content_hash'    => $document->get_content_hash(),
			'metadata'        => $document->get_metadata(),
		);
	}

	/**
	 * Builds active remote rate-limit backoff summaries for an admin snapshot.
	 *
	 * @param ImportSessionId $id Session id.
	 * @return array<string,mixed>
	 */
	private function get_remote_rate_limit_snapshot( ImportSessionId $id ) {
		$summaries = array();
		$items     = $this->get_store()->list_source_items_by_statuses(
			$id,
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
		}

		return array(
			'total'  => count( $summaries ),
			'recent' => array_slice( $summaries, 0, 5 ),
		);
	}

	/**
	 * Builds one active remote rate-limit backoff summary from source metadata.
	 *
	 * @param ImportSourceItem    $item       Source item.
	 * @param array<string,mixed> $rate_limit Stored rate-limit metadata.
	 * @return array<string,mixed>|null
	 */
	private function remote_rate_limit_summary_from_metadata( ImportSourceItem $item, array $rate_limit ) {
		$next_retry = isset( $rate_limit['next_retry_unix'] ) ? (int) $rate_limit['next_retry_unix'] : 0;

		if ( $next_retry <= time() ) {
			return null;
		}

		return array(
			'item_key'            => $item->get_key(),
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
	 * Builds PDF/OCR summaries for an admin snapshot.
	 *
	 * @param ImportSessionId $id Session id.
	 * @return array<string,mixed>
	 */
	private function get_pdf_document_snapshot( ImportSessionId $id ) {
		$summaries = array();
		$items     = $this->get_store()->list_source_items_by_statuses(
			$id,
			array(
				ImportSourceItem::STATUS_DISCOVERED,
				ImportSourceItem::STATUS_IMPORTED,
				ImportSourceItem::STATUS_FAILED,
			),
			100
		);

		foreach ( $items as $item ) {
			$metadata = $item->get_metadata();

			if ( 'pdf' !== ( isset( $metadata['document_format'] ) ? (string) $metadata['document_format'] : '' ) ) {
				continue;
			}

			$summaries[] = $this->pdf_document_summary_from_metadata( $item, $metadata );
		}

		return array(
			'total'  => count( $summaries ),
			'recent' => array_slice( $summaries, 0, 5 ),
		);
	}

	/**
	 * Builds one PDF/OCR summary from source item metadata.
	 *
	 * @param ImportSourceItem    $item     Source item.
	 * @param array<string,mixed> $metadata Source item metadata.
	 * @return array<string,string>
	 */
	private function pdf_document_summary_from_metadata( ImportSourceItem $item, array $metadata ) {
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
	 * Builds EPUB table-of-contents summaries for an admin snapshot.
	 *
	 * @param ImportSessionId $id Session id.
	 * @return array<string,mixed>
	 */
	private function get_epub_toc_snapshot( ImportSessionId $id ) {
		$summaries = array();
		$items     = $this->get_store()->list_source_items_by_statuses(
			$id,
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
		}

		return array(
			'total'  => count( $summaries ),
			'recent' => array_slice( $summaries, 0, 5 ),
		);
	}

	/**
	 * Builds one EPUB TOC summary from source item metadata.
	 *
	 * @param ImportSourceItem    $item     Source item.
	 * @param array<string,mixed> $metadata Source item metadata.
	 * @return array<string,mixed>
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
	 * @return array<int,array<string,string>>
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
	 * Builds media reference counts and recent detail for an admin snapshot.
	 *
	 * @param ImportSessionId $id Session id.
	 * @return array<string,mixed>
	 */
	private function get_media_reference_snapshot( ImportSessionId $id ) {
		$store    = $this->get_store();
		$statuses = array(
			ImportMediaReference::STATUS_QUEUED,
			ImportMediaReference::STATUS_IMPORTED,
			ImportMediaReference::STATUS_SKIPPED,
			ImportMediaReference::STATUS_FAILED,
		);
		$counts   = array();

		foreach ( $statuses as $status ) {
			$counts[ $status ] = $store->count_media_references_by_statuses( $id, array( $status ) );
		}

		return array(
			'total'    => array_sum( $counts ),
			'statuses' => $counts,
			'recent'   => array_map(
				array( $this, 'media_reference_to_snapshot' ),
				$store->list_recent_media_references( $id, 5 )
			),
		);
	}

	/**
	 * Converts a media reference to an admin snapshot.
	 *
	 * @param ImportMediaReference $reference Media reference.
	 * @return array<string,mixed>
	 */
	private function media_reference_to_snapshot( ImportMediaReference $reference ) {
		return $reference->to_array();
	}

	/**
	 * Builds relationship warning detail for admin status.
	 *
	 * @param ImportSessionId $id Session id.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_relationship_warning_snapshot( ImportSessionId $id ) {
		$warnings = array();

		foreach ( $this->get_store()->list_events( $id, 50 ) as $event ) {
			if ( ImportRelationshipMappingDecision::WARNING_EVENT !== $event->get_type() ) {
				continue;
			}

			$warnings[] = array(
				'summary'    => $this->summarize_relationship_warning( $event ),
				'event'      => $event->to_array(),
				'created_at' => $event->get_created_at(),
			);

			if ( 5 <= count( $warnings ) ) {
				break;
			}
		}

		return $warnings;
	}

	/**
	 * Builds a concise relationship warning summary.
	 *
	 * @param ImportProgressEvent $event Warning event.
	 * @return string
	 */
	private function summarize_relationship_warning( ImportProgressEvent $event ) {
		return ImportRelationshipMappingDecision::summarize_warning_event( $event, true );
	}

	/**
	 * Parses a comma-separated domain field.
	 *
	 * @param string $value Raw input.
	 * @return array<int,string>
	 */
	private function parse_domain_list( $value ) {
		return $this->normalize_domain_list( explode( ',', (string) $value ) );
	}

	/**
	 * Parses a structured decision answer from admin input.
	 *
	 * @param string $decision_key      Decision key.
	 * @param string $confirmed_domains Comma-separated confirmed domains.
	 * @param string $answer_json       Generic JSON object answer.
	 * @param string $url_choice        URL rewrite choice for first-party domain decisions.
	 * @return array<string,mixed>
	 * @throws InvalidArgumentException When input is missing or malformed.
	 */
	private function parse_decision_answer( $decision_key, $confirmed_domains, $answer_json, $url_choice = 'selected' ) {
		if ( 'confirm-first-party-domains' === trim( (string) $decision_key ) ) {
			if ( 'none' === strtolower( trim( (string) $url_choice ) ) ) {
				return array( 'confirmed_domains' => array() );
			}

			$domains = $this->parse_domain_list( $confirmed_domains );

			if ( empty( $domains ) ) {
				throw new InvalidArgumentException( 'Choose at least one source domain to rewrite, or choose not to rewrite discovered URLs.' );
			}

			return array( 'confirmed_domains' => $domains );
		}

		if ( '' === trim( (string) $answer_json ) ) {
			throw new InvalidArgumentException( 'A JSON object answer is required.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_decode_json_decode -- Admin decisions accept a portable JSON object.
		$answer = json_decode( (string) $answer_json, true );

		if ( ! is_array( $answer ) || JSON_ERROR_NONE !== json_last_error() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are sent through escaped admin/AJAX renderers.
			throw new InvalidArgumentException( 'Decision answer must be a valid JSON object: ' . json_last_error_msg() );
		}

		return $answer;
	}

	/**
	 * Normalizes a domain list.
	 *
	 * @param array<int,string> $domains Raw domains.
	 * @return array<int,string>
	 */
	private function normalize_domain_list( array $domains ) {
		$normalized = array();

		foreach ( $domains as $domain ) {
			$domain = strtolower( trim( (string) $domain ) );

			if ( '' !== $domain && ! in_array( $domain, $normalized, true ) ) {
				$normalized[] = $domain;
			}
		}

		return $normalized;
	}
}
