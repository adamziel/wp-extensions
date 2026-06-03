<?php
declare(strict_types=1);

$wp_fts_files = [
    __DIR__ . '/StorageInterface.php',
    __DIR__ . '/Analyzer.php',
    __DIR__ . '/PostingsCodec.php',
    __DIR__ . '/InMemoryStorage.php',
    __DIR__ . '/FileStorage.php',
    __DIR__ . '/MysqlStorage.php',
    __DIR__ . '/Indexer.php',
    __DIR__ . '/Searcher.php',
    __DIR__ . '/WPCLICommand.php',
];

foreach ($wp_fts_files as $wp_fts_file) {
    require_once $wp_fts_file;
}

unset($wp_fts_file, $wp_fts_files);
