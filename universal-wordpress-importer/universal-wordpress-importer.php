<?php
/**
 * Plugin Name: Universal WordPress Importer
 * Description: Imports document trees, archives, remote sites, and repositories into WordPress with resumable checkpoints.
 * Version: 0.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.2.24
 * Author: WordPress Contributors
 * License: GPL-2.0-or-later
 * Text Domain: universal-wordpress-importer
 *
 * @package UniversalImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'UNIVERSAL_IMPORTER_VERSION', '0.1.0' );
define( 'UNIVERSAL_IMPORTER_FILE', __FILE__ );
define( 'UNIVERSAL_IMPORTER_PATH', plugin_dir_path( __FILE__ ) );

$universal_importer_autoloader = __DIR__ . '/vendor/autoload.php';

if ( ! file_exists( $universal_importer_autoloader ) ) {
	add_action(
		'admin_notices',
		static function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Universal WordPress Importer is missing Composer dependencies. Run composer install in the plugin directory or install a packaged release.', 'universal-wordpress-importer' );
			echo '</p></div>';
		}
	);

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		WP_CLI::warning( 'Universal WordPress Importer is missing Composer dependencies. Run composer install in the plugin directory or install a packaged release.' );
	}

	return;
}

require_once $universal_importer_autoloader;

register_activation_hook( __FILE__, array( UniversalImporter\Import\WordPressImportSessionSchema::class, 'activate' ) );

add_action(
	'plugins_loaded',
	static function () {
		$plugin = new UniversalImporter\Plugin();
		$plugin->register();
	}
);
