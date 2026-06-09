<?php
/**
 * Plugin Name: Language FTS Playground
 * Plugin URI: https://github.com/adamziel/wp-extensions/tree/main/language-fts-playground
 * Description: SQLite-friendly language-partitioned full-text search demo for WordPress Playground.
 * Version: 0.3.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: Adam Zielinski
 * License: GPL-2.0-or-later
 * Text Domain: language-fts-playground
 *
 * @package LanguageFtsPlayground
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('LANGUAGE_FTS_PLAYGROUND_VERSION', '0.3.0');
define('LANGUAGE_FTS_PLAYGROUND_SCHEMA_VERSION', '5');
define('LANGUAGE_FTS_PLAYGROUND_ANALYZER_VERSION', '2026-06-09-resource-backed-term-rules');
define('LANGUAGE_FTS_PLAYGROUND_QUEUE_BATCH_SIZE', 25);
define('LANGUAGE_FTS_PLAYGROUND_PLUGIN_FILE', __FILE__);
define('LANGUAGE_FTS_PLAYGROUND_PLUGIN_DIR', __DIR__ . '/');

require_once LANGUAGE_FTS_PLAYGROUND_PLUGIN_DIR . 'src/bootstrap.php';

register_activation_hook(__FILE__, [Language_FTS_Playground_Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [Language_FTS_Playground_Plugin::class, 'deactivate']);

Language_FTS_Playground_Plugin::register_hooks();
