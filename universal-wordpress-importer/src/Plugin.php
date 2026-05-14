<?php
/**
 * Plugin bootstrap.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter;

use UniversalImporter\Admin\ImportAdminPage;
use UniversalImporter\Cli\ImportCommand;
use UniversalImporter\Import\ImportCacheDirectory;
use UniversalImporter\Import\ImportRunner;
use UniversalImporter\Import\ImportSessionId;
use UniversalImporter\Import\WordPressImportSessionSchema;
use RuntimeException;

/**
 * Registers WordPress integrations for the importer.
 */
final class Plugin {
	/**
	 * Cron hook used by future resumable import workers.
	 */
	const CRON_HOOK = 'universal_importer_continue_imports';

	/**
	 * Registers plugin hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( self::CRON_HOOK, array( $this, 'continue_imports' ), 10, 1 );
		add_action( 'universal_importer_cleanup_session_cache', array( $this, 'cleanup_session_cache' ), 10, 1 );

		if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			add_action( 'init', array( $this, 'maybe_install_schema' ) );
		}

		if ( is_admin() ) {
			ImportAdminPage::from_globals()->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'universal-importer', ImportCommand::class );
		}
	}

	/**
	 * Ensures importer tables exist after plugin updates.
	 *
	 * @return void
	 */
	public function maybe_install_schema() {
		WordPressImportSessionSchema::from_globals()->maybe_install();
	}

	/**
	 * Cron continuation hook.
	 *
	 * @param string|null $session_id Optional scheduled session id.
	 * @return void
	 */
	public function continue_imports( $session_id = null ) {
		/**
		 * Fires when the importer cron worker is ready to continue pending imports.
		 *
		 * @since 0.1.0
		 */
		do_action( 'universal_importer_continue_imports_requested', $session_id );

		$id = null;

		if ( null !== $session_id && '' !== (string) $session_id ) {
			$id = ImportSessionId::from_string( (string) $session_id );
		}

		ImportRunner::from_globals( array( $this, 'schedule_continuation' ) )->run( $id );
	}

	/**
	 * Schedules a follow-up import continuation tick for a runnable session.
	 *
	 * @param ImportSessionId $session_id Import session id.
	 * @return void
	 * @throws RuntimeException When WordPress cron cannot be scheduled.
	 */
	public function schedule_continuation( ImportSessionId $session_id ) {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_single_event' ) ) {
			throw new RuntimeException( 'WordPress cron scheduling functions are unavailable; cannot queue import continuation.' );
		}

		$args = array( $session_id->to_string() );

		if ( false === wp_next_scheduled( self::CRON_HOOK, $args ) ) {
			wp_schedule_single_event( time() + $this->continuation_delay_seconds(), self::CRON_HOOK, $args );
		}
	}

	/**
	 * Returns the delay before a cron worker schedules another import tick.
	 *
	 * @return int Delay in seconds.
	 */
	private function continuation_delay_seconds() {
		return defined( 'MINUTE_IN_SECONDS' ) ? (int) MINUTE_IN_SECONDS : 60;
	}

	/**
	 * Removes importer-owned cache files for a session.
	 *
	 * @param string $session_id Import session id.
	 * @return void
	 */
	public function cleanup_session_cache( $session_id ) {
		try {
			ImportCacheDirectory::from_environment()->remove_session( ImportSessionId::from_string( (string) $session_id ) );
		} catch ( \Exception $exception ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Cleanup diagnostics are only emitted when WP_DEBUG is enabled.
				error_log( 'Universal Importer cache cleanup failed: ' . $exception->getMessage() );
			}
		}
	}
}
