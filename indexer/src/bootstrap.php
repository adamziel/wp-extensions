<?php
declare(strict_types=1);

/**
 * Load the optional Composer dependencies first, then require source files in an
 * order that satisfies interface and helper dependencies without relying on an
 * autoloader. This keeps the plugin entrypoint usable in bare WordPress installs
 * and the standalone test harness.
 */
$wp_fts_vendor_autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($wp_fts_vendor_autoload)) {
    require_once $wp_fts_vendor_autoload;
}

$wp_fts_files = [
    __DIR__ . '/StorageInterface.php',
    __DIR__ . '/DocumentMetadataStorage.php',
    __DIR__ . '/TermNamespace.php',
    __DIR__ . '/Utf8.php',
    __DIR__ . '/StorageCompat.php',
    __DIR__ . '/Normalizer.php',
    __DIR__ . '/Stemmer.php',
    __DIR__ . '/AnalyzerPackValidator.php',
    __DIR__ . '/PolishMorfologikLemmatizer.php',
    __DIR__ . '/LanguageDetector.php',
    __DIR__ . '/LanguagePipeline.php',
    __DIR__ . '/Analyzer.php',
    __DIR__ . '/PostContentExtractor.php',
    __DIR__ . '/PostingsCodec.php',
    __DIR__ . '/InMemoryStorage.php',
    __DIR__ . '/FileStorage.php',
    __DIR__ . '/MysqlStorage.php',
    __DIR__ . '/Indexer.php',
    __DIR__ . '/Searcher.php',
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
unset($wp_fts_file, $wp_fts_files, $wp_fts_vendor_autoload);
