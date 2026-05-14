<?php
/**
 * WordPress admin surface for browser-driven imports.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Admin;

use InvalidArgumentException;
use RuntimeException;
use UniversalImporter\Import\ImportCacheDirectory;
use UniversalImporter\Import\ImportDecision;
use UniversalImporter\Import\ImportMediaReference;
use UniversalImporter\Import\ImportPreparedDocument;
use UniversalImporter\Import\ImportProgressEvent;
use UniversalImporter\Import\ImportRelationshipMappingDecision;
use UniversalImporter\Import\ImportRunner;
use UniversalImporter\Import\ImportSession;
use UniversalImporter\Import\ImportSessionId;
use UniversalImporter\Import\ImportSourceItem;
use UniversalImporter\Import\WordPressImportSessionStore;
use UniversalImporter\Plugin;

/**
 * Registers and serves the importer admin page and keepalive endpoints.
 */
final class ImportAdminPage {
	const PAGE_SLUG        = 'universal-wordpress-importer';
	const NONCE_ACTION     = 'universal_importer_admin';
	const AJAX_CREATE      = 'universal_importer_create_session';
	const AJAX_UPLOAD      = 'universal_importer_upload_session';
	const AJAX_KEEPALIVE   = 'universal_importer_keepalive';
	const AJAX_ABORT       = 'universal_importer_abort_session';
	const AJAX_DECIDE      = 'universal_importer_resolve_decision';
	const CAPABILITY       = 'manage_options';
	const RECENT_SESSIONS  = 10;
	const MAX_UPLOAD_FILES = 500;
	const MAX_UPLOAD_BYTES = 134217728;

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
	 * Constructor.
	 *
	 * @param WordPressImportSessionStore|null $store          Optional session store.
	 * @param callable|null                    $scheduler      Optional continuation scheduler.
	 * @param callable|null                    $runner_factory Optional runner factory.
	 * @param ImportCacheDirectory|null        $cache_directory Optional upload cache directory.
	 */
	public function __construct( WordPressImportSessionStore $store = null, callable $scheduler = null, callable $runner_factory = null, ImportCacheDirectory $cache_directory = null ) {
		$this->store           = $store;
		$this->scheduler       = $scheduler;
		$this->runner_factory  = $runner_factory;
		$this->cache_directory = $cache_directory;
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
		add_action( 'wp_ajax_' . self::AJAX_CREATE, array( $this, 'ajax_create_session' ) );
		add_action( 'wp_ajax_' . self::AJAX_UPLOAD, array( $this, 'ajax_upload_session' ) );
		add_action( 'wp_ajax_' . self::AJAX_KEEPALIVE, array( $this, 'ajax_keepalive' ) );
		add_action( 'wp_ajax_' . self::AJAX_ABORT, array( $this, 'ajax_abort_session' ) );
		add_action( 'wp_ajax_' . self::AJAX_DECIDE, array( $this, 'ajax_resolve_decision' ) );
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
	 * Creates and queues an import session from admin input.
	 *
	 * @param string            $source            Source path or URL.
	 * @param array<int,string> $confirmed_domains Confirmed first-party domains.
	 * @param bool              $dry_run           Whether this is a dry run.
	 * @return array<string,mixed>
	 * @throws InvalidArgumentException When source input is invalid.
	 */
	public function create_import_session( $source, array $confirmed_domains = array(), $dry_run = false ) {
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

		$confirmed_domains = $this->normalize_domain_list( $confirmed_domains );

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

		return $this->get_status_snapshot( $session->get_id() );
	}

	/**
	 * Stages browser-uploaded files into importer cache and queues a directory import.
	 *
	 * @param array<int,array<string,mixed>> $files             Uploaded file rows.
	 * @param array<int,string>              $relative_paths    Browser-provided relative paths.
	 * @param array<int,string>              $confirmed_domains Confirmed first-party domains.
	 * @param bool                           $dry_run           Whether this is a dry run.
	 * @return array<string,mixed>
	 * @throws InvalidArgumentException When upload input is invalid or staging fails.
	 */
	public function create_import_session_from_uploaded_files( array $files, array $relative_paths = array(), array $confirmed_domains = array(), $dry_run = false ) {
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

		return $this->get_status_snapshot( $session->get_id() );
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

		$runner  = $this->create_runner();
		$summary = $runner->run( $id );

		return array(
			'summary' => $summary,
			'session' => null === $id ? null : $this->get_status_snapshot( $id ),
		);
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

		$checkpoint = $session->get_checkpoint();
		$source_id  = $session->get_id();

		return array(
			'id'                    => $session->get_id()->to_string(),
			'source'                => $session->get_source(),
			'status'                => $session->get_status(),
			'dry_run'               => $session->is_dry_run(),
			'progress'              => $session->get_progress()->to_array(),
			'checkpoint'            => null === $checkpoint ? null : $checkpoint->to_array(),
			'source_items'          => $this->get_source_item_snapshot( $source_id ),
			'prepared_documents'    => array(
				'total'  => $store->count_prepared_documents( $source_id ),
				'recent' => array_map(
					array( $this, 'prepared_document_to_snapshot' ),
					$store->list_recent_prepared_documents( $source_id, 5 )
				),
			),
			'posts'                 => array(
				'persisted' => $store->count_idempotency_records_by_resource_type( $source_id, 'post' ),
			),
			'comments'              => array(
				'persisted' => $store->count_idempotency_records_by_resource_type( $source_id, 'comment' ),
			),
			'media'                 => $this->get_media_reference_snapshot( $source_id ),
			'remote_backoff'        => $this->get_remote_rate_limit_snapshot( $source_id ),
			'pdf_documents'         => $this->get_pdf_document_snapshot( $source_id ),
			'epub_tocs'             => $this->get_epub_toc_snapshot( $source_id ),
			'relationship_warnings' => $this->get_relationship_warning_snapshot( $source_id ),
			'pending_decisions'     => array_map(
				array( $this, 'decision_to_snapshot' ),
				$store->list_pending_decisions( $session->get_id() )
			),
			'recent_events'         => array_map(
				array( $this, 'event_to_snapshot' ),
				$store->list_events( $session->get_id(), 8 )
			),
		);
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

		$config = array(
			'nonce'    => $nonce,
			'sessions' => $sessions,
		);

		?>
		<div class="wrap universal-importer-admin">
			<h1><?php esc_html_e( 'Universal Importer', 'universal-wordpress-importer' ); ?></h1>
			<?php if ( '' !== $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>
			<div id="universal-importer-notice" class="notice" style="display:none"><p></p></div>
			<form id="universal-importer-start-form">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="universal-importer-source"><?php esc_html_e( 'Source', 'universal-wordpress-importer' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="universal-importer-source" name="source" required>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="universal-importer-files"><?php esc_html_e( 'Browser files', 'universal-wordpress-importer' ); ?></label></th>
						<td>
							<div id="universal-importer-dropzone" class="notice notice-info inline" style="padding:12px">
								<p><?php esc_html_e( 'Drop files or folders here, or choose a folder from this browser.', 'universal-wordpress-importer' ); ?></p>
								<input type="file" id="universal-importer-files" multiple webkitdirectory directory>
								<p id="universal-importer-file-summary" aria-live="polite"></p>
							</div>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="universal-importer-domains"><?php esc_html_e( 'Confirmed first-party domains', 'universal-wordpress-importer' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="universal-importer-domains" name="confirmed_domains">
						</td>
					</tr>
				</table>
				<p>
					<label>
						<input type="checkbox" name="dry_run" value="1">
						<?php esc_html_e( 'Dry run', 'universal-wordpress-importer' ); ?>
					</label>
				</p>
				<?php submit_button( __( 'Start import', 'universal-wordpress-importer' ), 'primary', 'submit', false ); ?>
			</form>
			<hr>
			<h2><?php esc_html_e( 'Recent sessions', 'universal-wordpress-importer' ); ?></h2>
			<div id="universal-importer-sessions">
				<?php $this->render_session_list( $sessions ); ?>
			</div>
		</div>
		<script>
		(function() {
			var config = <?php echo wp_json_encode( $config ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode returns JavaScript-safe JSON. ?>;
			var form = document.getElementById('universal-importer-start-form');
			var sourceInput = document.getElementById('universal-importer-source');
			var fileInput = document.getElementById('universal-importer-files');
			var dropzone = document.getElementById('universal-importer-dropzone');
			var fileSummary = document.getElementById('universal-importer-file-summary');
			var sessions = document.getElementById('universal-importer-sessions');
			var notice = document.getElementById('universal-importer-notice');
			var activeSessionId = null;
			var timer = null;
			var browserFiles = [];

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
					return response.json();
				}).then(function(payload) {
					if (!payload || !payload.success) {
						throw new Error(payload && payload.data && payload.data.message ? payload.data.message : 'Importer request failed.');
					}
					return payload.data;
				});
			}

			function filePath(file) {
				return file.universalImporterRelativePath || file.webkitRelativePath || file.name;
			}

			function setBrowserFiles(files) {
				browserFiles = files || [];
				sourceInput.required = browserFiles.length < 1;
				if (!browserFiles.length) {
					fileSummary.textContent = '';
					return;
				}
				fileSummary.textContent = browserFiles.length + ' file' + (browserFiles.length === 1 ? '' : 's') + ' ready from browser selection.';
			}

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
				var total = session.progress.total === null ? '?' : session.progress.total;
				var html = '<div class="postbox" data-session-id="' + escapeHtml(session.id) + '">';
				html += '<div class="inside">';
				html += '<h3>' + escapeHtml(session.source) + '</h3>';
				html += '<p><strong>Status:</strong> ' + escapeHtml(session.status) + ' <strong>Dry run:</strong> ' + (session.dry_run ? 'yes' : 'no') + ' <strong>Progress:</strong> ' + total + ' total, ' + session.progress.completed + ' completed, ' + session.progress.errors + ' errors</p>';
				html += '<p><code>' + escapeHtml(session.id) + '</code></p>';
				html += renderPipeline(session);
				if (session.relationship_warnings && session.relationship_warnings.length) {
					html += renderRelationshipWarnings(session.relationship_warnings);
				}
				if (session.recent_events.length) {
					html += '<ul>';
					session.recent_events.forEach(function(event) {
						html += '<li><strong>' + escapeHtml(event.type) + ':</strong> ' + escapeHtml(event.message) + '</li>';
					});
					html += '</ul>';
				}
				if (session.pending_decisions.length) {
					html += renderDecisions(session);
				}
				if (session.status !== 'done' && session.status !== 'aborted') {
					html += '<p><button type="button" class="button universal-importer-abort" data-session-id="' + escapeHtml(session.id) + '"><?php echo esc_js( __( 'Abort', 'universal-wordpress-importer' ) ); ?></button></p>';
				}
				html += '</div></div>';
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
				var html = '<div class="universal-importer-pipeline">';
				html += '<p><strong><?php echo esc_js( __( 'Source items:', 'universal-wordpress-importer' ) ); ?></strong> ' + sourceItems.total + ' total';
				html += ' <span>(' + (statuses.queued || 0) + ' queued, ' + (statuses.processing || 0) + ' processing, ' + (statuses.discovered || 0) + ' discovered, ' + (statuses.imported || 0) + ' imported, ' + (statuses.skipped || 0) + ' skipped, ' + (statuses.failed || 0) + ' failed)</span></p>';
				html += '<p><strong><?php echo esc_js( __( 'Prepared documents:', 'universal-wordpress-importer' ) ); ?></strong> ' + documents.total + ' <strong><?php echo esc_js( __( 'Persisted posts:', 'universal-wordpress-importer' ) ); ?></strong> ' + posts.persisted + ' <strong><?php echo esc_js( __( 'Imported comments:', 'universal-wordpress-importer' ) ); ?></strong> ' + comments.persisted + '</p>';
				html += '<p><strong><?php echo esc_js( __( 'Media references:', 'universal-wordpress-importer' ) ); ?></strong> ' + media.total + ' <span>(' + (mediaStatuses.queued || 0) + ' queued, ' + (mediaStatuses.imported || 0) + ' imported, ' + (mediaStatuses.skipped || 0) + ' skipped, ' + (mediaStatuses.failed || 0) + ' failed)</span></p>';
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
						html += '<li><code>' + escapeHtml(item.status) + '</code> ' + escapeHtml(item.relative_path || item.source_uri) + ' <span>(' + escapeHtml(item.type) + ')</span></li>';
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
				html += '</div>';
				return html;
			}

			function renderDecisions(session) {
				var html = '<div class="universal-importer-decisions"><h4><?php echo esc_js( __( 'Pending decisions', 'universal-wordpress-importer' ) ); ?></h4>';
				session.pending_decisions.forEach(function(decision) {
					html += '<div class="universal-importer-decision" data-decision-key="' + escapeHtml(decision.key) + '">';
					html += '<p><strong>' + escapeHtml(decision.key) + ':</strong> ' + escapeHtml(decision.prompt) + '</p>';
					if (decision.key === 'confirm-first-party-domains') {
						html += '<p><input type="text" class="regular-text universal-importer-decision-domains" value="' + escapeHtml((decision.options.domains || []).join(', ')) + '"></p>';
					} else {
						html += '<p><textarea class="large-text universal-importer-decision-answer" rows="6">' + escapeHtml(JSON.stringify(getDecisionAnswerTemplate(decision), null, 2)) + '</textarea></p>';
					}
					html += '<p><button type="button" class="button universal-importer-resolve-decision" data-session-id="' + escapeHtml(session.id) + '" data-decision-key="' + escapeHtml(decision.key) + '"><?php echo esc_js( __( 'Resolve decision', 'universal-wordpress-importer' ) ); ?></button></p>';
					html += '</div>';
				});
				html += '</div>';
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

			function findSessionElement(sessionId) {
				var nodes = sessions.querySelectorAll('[data-session-id]');
				for (var index = 0; index < nodes.length; index++) {
					if (nodes[index].getAttribute('data-session-id') === sessionId) {
						return nodes[index];
					}
				}
				return null;
			}

			function rememberSession(session) {
				var recentSessions = config.sessions || [];
				for (var index = 0; index < recentSessions.length; index++) {
					if (recentSessions[index].id === session.id) {
						recentSessions[index] = session;
						config.sessions = recentSessions;
						return;
					}
				}
				recentSessions.unshift(session);
				config.sessions = recentSessions;
			}

			function upsertSession(session) {
				rememberSession(session);
				var existing = findSessionElement(session.id);
				var wrapper = document.createElement('div');
				wrapper.innerHTML = renderSession(session);
				if (existing) {
					existing.replaceWith(wrapper.firstElementChild);
				} else {
					sessions.insertBefore(wrapper.firstElementChild, sessions.firstChild);
				}
			}

			function sessionNeedsKeepalive(session) {
				return session
					&& session.id
					&& session.status !== 'done'
					&& session.status !== 'aborted'
					&& session.status !== 'failed'
					&& !(session.pending_decisions && session.pending_decisions.length);
			}

			function startKeepalive(sessionId) {
				activeSessionId = sessionId;
				if (!timer) {
					timer = window.setInterval(tick, 5000);
				}
			}

			function reattachActiveSession() {
				var recentSessions = config.sessions || [];
				for (var index = 0; index < recentSessions.length; index++) {
					if (sessionNeedsKeepalive(recentSessions[index])) {
						startKeepalive(recentSessions[index].id);
						tick();
						return;
					}
				}
			}

			function tick() {
				if (!activeSessionId) {
					return;
				}
				request('<?php echo esc_js( self::AJAX_KEEPALIVE ); ?>', { session_id: activeSessionId }).then(function(data) {
					if (data.session) {
						upsertSession(data.session);
						if (data.session.status === 'done' || data.session.status === 'aborted' || data.session.status === 'failed' || data.session.pending_decisions.length) {
							window.clearInterval(timer);
							timer = null;
							activeSessionId = null;
							reattachActiveSession();
						}
					}
				}).catch(function(error) {
					showNotice(error.message, 'error');
				});
			}

			form.addEventListener('submit', function(event) {
				event.preventDefault();
				var data = new FormData(form);
				var action = '<?php echo esc_js( self::AJAX_CREATE ); ?>';
				var payload = {
					source: data.get('source') || '',
					confirmed_domains: data.get('confirmed_domains') || '',
					dry_run: data.get('dry_run') ? '1' : ''
				};

				if (browserFiles.length) {
					action = '<?php echo esc_js( self::AJAX_UPLOAD ); ?>';
					payload = new FormData();
					payload.set('confirmed_domains', data.get('confirmed_domains') || '');
					payload.set('dry_run', data.get('dry_run') ? '1' : '');
					browserFiles.forEach(function(file) {
						payload.append('files[]', file, file.name);
						payload.append('paths[]', filePath(file));
					});
				}

				request(action, payload).then(function(session) {
					upsertSession(session);
					showNotice('Import session created and queued.', 'success');
					startKeepalive(session.id);
					tick();
				}).catch(function(error) {
					showNotice(error.message, 'error');
				});
			});

			fileInput.addEventListener('change', function() {
				setBrowserFiles(Array.prototype.slice.call(fileInput.files || []));
			});

			['dragenter', 'dragover'].forEach(function(type) {
				dropzone.addEventListener(type, function(event) {
					event.preventDefault();
					dropzone.classList.add('notice-warning');
				});
			});

			['dragleave', 'drop'].forEach(function(type) {
				dropzone.addEventListener(type, function(event) {
					event.preventDefault();
					dropzone.classList.remove('notice-warning');
				});
			});

			dropzone.addEventListener('drop', function(event) {
				filesFromDrop(event.dataTransfer).then(setBrowserFiles).catch(function(error) {
					showNotice(error.message, 'error');
				});
			});

			sessions.addEventListener('click', function(event) {
				if (!event.target.classList.contains('universal-importer-abort')) {
					if (!event.target.classList.contains('universal-importer-resolve-decision')) {
						return;
					}
					var button = event.target;
					var decision = button.closest('.universal-importer-decision');
					var data = {
						session_id: button.getAttribute('data-session-id'),
						decision_key: button.getAttribute('data-decision-key')
					};
					var domains = decision.querySelector('.universal-importer-decision-domains');
					var answer = decision.querySelector('.universal-importer-decision-answer');
					if (domains) {
						data.confirmed_domains = domains.value;
					}
					if (answer) {
						data.answer = answer.value;
					}
					request('<?php echo esc_js( self::AJAX_DECIDE ); ?>', data).then(function(session) {
						upsertSession(session);
						showNotice('Import decision resolved.', 'success');
						startKeepalive(session.id);
						tick();
					}).catch(function(error) {
						showNotice(error.message, 'error');
					});
					return;
				}
				request('<?php echo esc_js( self::AJAX_ABORT ); ?>', { session_id: event.target.getAttribute('data-session-id') }).then(function(session) {
					upsertSession(session);
					showNotice('Import session aborted.', 'warning');
				}).catch(function(error) {
					showNotice(error.message, 'error');
				});
			});

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

			wp_send_json_success( $this->create_import_session( $source, $confirmed_domains, $dry_run ) );
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

			wp_send_json_success( $this->create_import_session_from_uploaded_files( $files, $paths, $confirmed_domains, $dry_run ) );
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
				$this->read_post_string( 'answer' )
			);

			wp_send_json_success( $this->resolve_import_decision( $session_id, $decision_key, $answer ) );
		} catch ( InvalidArgumentException $exception ) {
			wp_send_json_error( array( 'message' => $exception->getMessage() ), 400 );
		} catch ( RuntimeException $exception ) {
			wp_send_json_error( array( 'message' => $exception->getMessage() ), 500 );
		}
	}

	/**
	 * Renders a list of session snapshots.
	 *
	 * @param array<int,array<string,mixed>> $sessions Session snapshots.
	 * @return void
	 */
	private function render_session_list( array $sessions ) {
		if ( empty( $sessions ) ) {
			echo '<p>' . esc_html__( 'No import sessions yet.', 'universal-wordpress-importer' ) . '</p>';
			return;
		}

		foreach ( $sessions as $session ) {
			$progress = $session['progress'];
			$total    = null === $progress['total'] ? '?' : (string) $progress['total'];
			?>
			<div class="postbox" data-session-id="<?php echo esc_attr( $session['id'] ); ?>">
				<div class="inside">
					<h3><?php echo esc_html( $session['source'] ); ?></h3>
					<p>
						<strong><?php esc_html_e( 'Status:', 'universal-wordpress-importer' ); ?></strong>
						<?php echo esc_html( $session['status'] ); ?>
						<strong><?php esc_html_e( 'Dry run:', 'universal-wordpress-importer' ); ?></strong>
						<?php echo $session['dry_run'] ? esc_html__( 'yes', 'universal-wordpress-importer' ) : esc_html__( 'no', 'universal-wordpress-importer' ); ?>
						<strong><?php esc_html_e( 'Progress:', 'universal-wordpress-importer' ); ?></strong>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: total items, 2: completed items, 3: error count. */
								__( '%1$s total, %2$d completed, %3$d errors', 'universal-wordpress-importer' ),
								$total,
								$progress['completed'],
								$progress['errors']
							)
						);
						?>
					</p>
					<p><code><?php echo esc_html( $session['id'] ); ?></code></p>
					<?php $this->render_pipeline_details( $session ); ?>
					<?php $this->render_relationship_warnings( $session ); ?>
					<?php if ( ! empty( $session['recent_events'] ) ) : ?>
						<ul>
							<?php foreach ( $session['recent_events'] as $event ) : ?>
								<li><strong><?php echo esc_html( $event['type'] ); ?>:</strong> <?php echo esc_html( $event['message'] ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
					<?php $this->render_pending_decisions( $session ); ?>
					<?php if ( ImportSession::STATUS_DONE !== $session['status'] && ImportSession::STATUS_ABORTED !== $session['status'] ) : ?>
						<p><button type="button" class="button universal-importer-abort" data-session-id="<?php echo esc_attr( $session['id'] ); ?>"><?php esc_html_e( 'Abort', 'universal-wordpress-importer' ); ?></button></p>
					<?php endif; ?>
				</div>
			</div>
			<?php
		}
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
		<div class="universal-importer-pipeline">
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
						<li><code><?php echo esc_html( $item['status'] ); ?></code> <?php echo esc_html( '' === $item['relative_path'] ? $item['source_uri'] : $item['relative_path'] ); ?> <span>(<?php echo esc_html( $item['type'] ); ?>)</span></li>
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
		</div>
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
	 * @param array<string,mixed> $session Session snapshot.
	 * @return void
	 */
	private function render_pending_decisions( array $session ) {
		if ( empty( $session['pending_decisions'] ) ) {
			return;
		}

		?>
		<div class="universal-importer-decisions">
			<h4><?php esc_html_e( 'Pending decisions', 'universal-wordpress-importer' ); ?></h4>
			<?php foreach ( $session['pending_decisions'] as $decision ) : ?>
				<div class="universal-importer-decision" data-decision-key="<?php echo esc_attr( $decision['key'] ); ?>">
					<p><strong><?php echo esc_html( $decision['key'] ); ?>:</strong> <?php echo esc_html( $decision['prompt'] ); ?></p>
					<?php if ( 'confirm-first-party-domains' === $decision['key'] ) : ?>
						<?php $domains = isset( $decision['options']['domains'] ) && is_array( $decision['options']['domains'] ) ? implode( ', ', $decision['options']['domains'] ) : ''; ?>
						<p><input type="text" class="regular-text universal-importer-decision-domains" value="<?php echo esc_attr( $domains ); ?>"></p>
					<?php else : ?>
						<p><textarea class="large-text universal-importer-decision-answer" rows="6"><?php echo esc_textarea( $this->decision_answer_template_json( $decision ) ); ?></textarea></p>
					<?php endif; ?>
					<p><button type="button" class="button universal-importer-resolve-decision" data-session-id="<?php echo esc_attr( $session['id'] ); ?>" data-decision-key="<?php echo esc_attr( $decision['key'] ); ?>"><?php esc_html_e( 'Resolve decision', 'universal-wordpress-importer' ); ?></button></p>
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
		$counts   = array();

		foreach ( $statuses as $status ) {
			$counts[ $status ] = $store->count_source_items_by_statuses( $id, array( $status ) );
		}

		return array(
			'total'    => array_sum( $counts ),
			'statuses' => $counts,
			'recent'   => array_map(
				array( $this, 'source_item_to_snapshot' ),
				$store->list_recent_source_items( $id, 8 )
			),
		);
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
	 * @return array<string,mixed>
	 * @throws InvalidArgumentException When input is missing or malformed.
	 */
	private function parse_decision_answer( $decision_key, $confirmed_domains, $answer_json ) {
		if ( 'confirm-first-party-domains' === trim( (string) $decision_key ) ) {
			$domains = $this->parse_domain_list( $confirmed_domains );

			if ( empty( $domains ) ) {
				throw new InvalidArgumentException( 'At least one confirmed domain is required.' );
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
