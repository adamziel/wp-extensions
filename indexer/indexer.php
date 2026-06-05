<?php
/**
 * Plugin Name: Pure PHP FTS Indexer
 * Description: HTML-aware pure-PHP full-text search engine for WordPress.
 * Version: 0.1.0
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

if (defined('WP_CLI') && WP_CLI && class_exists('WP_FTS_WPCLI_Command')) {
    WP_FTS_WPCLI_Command::register();
}
