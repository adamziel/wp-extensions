<?php
/**
 * Plugin Name: Pure PHP FTS Indexer
 * Description: HTML-aware pure-PHP full-text search engine for WordPress.
 * Version: 0.1.6
 * Requires PHP: 8.1
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

if (function_exists('register_uninstall_hook')) {
    register_uninstall_hook(__FILE__, [WP_FTS_Plugin::class, 'uninstall']);
}

WP_FTS_Plugin::register_hooks();

if (defined('WP_CLI') && WP_CLI && class_exists('WP_FTS_WPCLI_Command')) {
    WP_FTS_WPCLI_Command::register();
}
