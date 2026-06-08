<?php
declare(strict_types=1);

if (!defined('LANGUAGE_FTS_PLAYGROUND_VERSION')) {
    define('LANGUAGE_FTS_PLAYGROUND_VERSION', '0.3.0');
}

if (!defined('LANGUAGE_FTS_PLAYGROUND_SCHEMA_VERSION')) {
    define('LANGUAGE_FTS_PLAYGROUND_SCHEMA_VERSION', '3');
}

if (!defined('LANGUAGE_FTS_PLAYGROUND_ANALYZER_VERSION')) {
    define('LANGUAGE_FTS_PLAYGROUND_ANALYZER_VERSION', '2026-06-09-custom-lexical-pack-root');
}

if (!defined('LANGUAGE_FTS_PLAYGROUND_QUEUE_BATCH_SIZE')) {
    define('LANGUAGE_FTS_PLAYGROUND_QUEUE_BATCH_SIZE', 25);
}

require_once __DIR__ . '/StorageInterface.php';
require_once __DIR__ . '/LexicalProfileRepository.php';
require_once __DIR__ . '/Analyzer.php';
require_once __DIR__ . '/InMemoryStorage.php';
require_once __DIR__ . '/WpdbStorage.php';
require_once __DIR__ . '/Indexer.php';
require_once __DIR__ . '/Searcher.php';
require_once __DIR__ . '/Demo.php';
require_once __DIR__ . '/Plugin.php';
