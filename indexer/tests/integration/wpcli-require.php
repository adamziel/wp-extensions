<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/indexer.php';

$wpFtsRealPrefix = getenv('WP_FTS_REAL_WPCLI_PREFIX');
if (is_string($wpFtsRealPrefix) && preg_match('/^[A-Za-z0-9_]+$/', $wpFtsRealPrefix) === 1) {
    $wpFtsApplyPrefix = static function () use ($wpFtsRealPrefix): void {
        global $wpdb;

        if (isset($wpdb) && is_object($wpdb)) {
            $wpdb->prefix = $wpFtsRealPrefix;
        }
    };

    if (class_exists('WP_CLI') && method_exists('WP_CLI', 'add_hook')) {
        WP_CLI::add_hook('after_wp_load', $wpFtsApplyPrefix);
    }
    if (class_exists('WP_CLI') && method_exists('WP_CLI', 'add_wp_hook')) {
        WP_CLI::add_wp_hook('plugins_loaded', $wpFtsApplyPrefix);
    }
    $wpFtsApplyPrefix();
}

unset($wpFtsApplyPrefix, $wpFtsRealPrefix);
