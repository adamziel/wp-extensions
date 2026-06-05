<?php
/**
 * Plugin Name: Pure PHP FTS Indexer
 * Description: HTML-aware pure-PHP full-text search engine for WordPress.
 * Version: 0.1.0
 * Requires PHP: 8.1
 */

declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

if (defined('WP_CLI') && WP_CLI && class_exists('WP_FTS_WPCLI_Command')) {
    WP_FTS_WPCLI_Command::register();
}
