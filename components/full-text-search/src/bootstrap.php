<?php
declare(strict_types=1);

if (class_exists('WP_FTS_Analyzer', false)) {
    return;
}

/**
 * Load optional Composer dependencies first, then require the legacy global
 * classes in dependency order. Namespacing is intentionally deferred so the
 * WordPress plugin and existing tests keep their current `WP_FTS_*` API.
 */
$wp_fts_component_vendor_autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($wp_fts_component_vendor_autoload)) {
    require_once $wp_fts_component_vendor_autoload;
}

$wp_fts_component_files = [
    __DIR__ . '/StorageInterface.php',
    __DIR__ . '/DocumentMetadataStorage.php',
    __DIR__ . '/TermNamespace.php',
    __DIR__ . '/Utf8.php',
    __DIR__ . '/HtmlTextStream.php',
    __DIR__ . '/AnalysisLimits.php',
    __DIR__ . '/AnalyzerConfigLimits.php',
    __DIR__ . '/LemmaPackLimits.php',
    __DIR__ . '/StorageCompat.php',
    __DIR__ . '/Normalizer.php',
    __DIR__ . '/EnglishSnowballStemmer.php',
    __DIR__ . '/ArabicSnowballStemmer.php',
    __DIR__ . '/SpanishSnowballStemmer.php',
    __DIR__ . '/FrenchSnowballStemmer.php',
    __DIR__ . '/HindiSnowballStemmer.php',
    __DIR__ . '/PortugueseSnowballStemmer.php',
    __DIR__ . '/IndonesianSnowballStemmer.php',
    __DIR__ . '/PolishVerifiedStemmerData.php',
    __DIR__ . '/Stemmer.php',
    __DIR__ . '/AnalyzerPackValidator.php',
    __DIR__ . '/ConfiguredLemmaPackAdmission.php',
    __DIR__ . '/LemmaPackLookupIndex.php',
    __DIR__ . '/LanguageLemmaPack.php',
    __DIR__ . '/PolishMorfologikLemmatizer.php',
    __DIR__ . '/ChineseJiebaSegmenter.php',
    __DIR__ . '/LanguageDetector.php',
    __DIR__ . '/TokenizerSourceLockVerifier.php',
    __DIR__ . '/TokenizerSourceCandidateLockVerifier.php',
    __DIR__ . '/LanguagePipeline.php',
    __DIR__ . '/Analyzer.php',
    __DIR__ . '/PostingsCodec.php',
    __DIR__ . '/Indexer.php',
    __DIR__ . '/Searcher.php',
];

foreach ($wp_fts_component_files as $wp_fts_component_file) {
    require_once $wp_fts_component_file;
}

// File and in-memory engines are legacy test/demo fixtures. Loading their
// full array/file implementations on every WordPress request adds parse and
// opcache pressure to a production path that must never instantiate them.
spl_autoload_register(static function (string $class): void {
    $file = match ($class) {
        'WP_FTS_Storage_InMemory' => __DIR__ . '/InMemoryStorage.php',
        'WP_FTS_Storage_File' => __DIR__ . '/FileStorage.php',
        default => null,
    };
    if ($file !== null) {
        require_once $file;
    }
}, true, true);

unset($wp_fts_component_file, $wp_fts_component_files, $wp_fts_component_vendor_autoload);
