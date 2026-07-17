<?php
declare(strict_types=1);

/**
 * Prefer the adjacent component source in monorepo checkouts so local edits and
 * Playground source previews do not depend on a mirrored vendor copy.
 */
$wp_fts_component_bootstrap = dirname(__DIR__, 2) . '/components/full-text-search/src/bootstrap.php';
if (is_file($wp_fts_component_bootstrap) && !class_exists('WP_FTS_Analyzer', false)) {
    require_once $wp_fts_component_bootstrap;
}

/**
 * Load Composer dependencies when a release ZIP or local install has vendor
 * files. Composer may also load the component package; its bootstrap is guarded
 * against duplicate class loading.
 */
$wp_fts_vendor_autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($wp_fts_vendor_autoload)) {
    require_once $wp_fts_vendor_autoload;
}

if (!class_exists('WP_FTS_Analyzer', false)) {
    if (!is_file($wp_fts_component_bootstrap)) {
        throw new RuntimeException('Pure PHP FTS requires the wp-php-toolkit/full-text-search component. Run Composer install or keep components/full-text-search next to indexer/.');
    }

    require_once $wp_fts_component_bootstrap;
}

$wp_fts_files = [
    __DIR__ . '/PostContentExtractor.php',
    __DIR__ . '/IndexQueue.php',
    __DIR__ . '/MysqlStorage.php',
    __DIR__ . '/Plugin.php',
    __DIR__ . '/WPCLICommand.php',
];

foreach ($wp_fts_files as $wp_fts_file) {
    require_once $wp_fts_file;
}

/**
 * Avoid leaking bootstrap-only variables into the global namespace after the
 * plugin file includes this script.
 */
unset($wp_fts_component_bootstrap, $wp_fts_file, $wp_fts_files, $wp_fts_vendor_autoload);
