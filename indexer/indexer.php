<?php
/**
 * Plugin Name: Language FTS
 * Description: Local full-text search for WordPress with multilingual analysis, WP-CLI tools, and operator diagnostics.
 * Version: 0.1.9
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: adamziel
 * Author URI: https://github.com/adamziel
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: language-fts
 */

declare(strict_types=1);

/**
 * WordPress plugin entrypoint.
 *
 * The bootstrap file loads the standalone PHP classes in dependency order. When
 * WP-CLI is active, registering the command here keeps normal web requests free
 * of command setup while exposing `wp fts ...` in CLI sessions.
 */
require_once __DIR__ . '/src/bootstrap.php';

if (function_exists('register_activation_hook')) {
    register_activation_hook(__FILE__, [WP_FTS_Plugin::class, 'activate']);
}

if (function_exists('register_deactivation_hook')) {
    register_deactivation_hook(__FILE__, [WP_FTS_Plugin::class, 'deactivate']);
}

WP_FTS_Plugin::register_hooks();

if (defined('WP_CLI') && WP_CLI && class_exists('WP_FTS_WPCLI_Command')) {
    WP_FTS_WPCLI_Command::register();
}
