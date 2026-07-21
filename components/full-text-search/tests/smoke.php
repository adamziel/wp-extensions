<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

$analyzer = new WP_FTS_Analyzer(['default_lang' => 'en']);
$storage = new WP_FTS_Storage_InMemory();
$indexer = new WP_FTS_Indexer($storage, $analyzer);

$indexed = $indexer->index_document(
    1,
    '<article><h1>Searchable Library</h1><p>Portable full text search works.</p></article>',
    ['lang' => 'en']
);
if (!$indexed) {
    fwrite(STDERR, "Expected smoke document to be indexed.\n");
    exit(1);
}

$searcher = new WP_FTS_Searcher($storage, $analyzer);
$results = $searcher->search('portable search', ['mode' => 'AND', 'lang' => 'en']);
if (($results[0]['doc_id'] ?? null) !== 1) {
    fwrite(STDERR, "Expected smoke search to find document 1.\n");
    exit(1);
}

$hardeningChecks = require __DIR__ . '/coverage-hardening.php';
$hardeningChecks += require __DIR__ . '/lemma-pack-limits.php';
$hardeningChecks += require __DIR__ . '/jieba-scan-bounds.php';
$hardeningChecks += require __DIR__ . '/jieba-line-bounds.php';
$hardeningChecks += require __DIR__ . '/jieba-cache-bounds.php';
$hardeningChecks += require __DIR__ . '/jieba-indexed-multi-run.php';
$hardeningChecks += require __DIR__ . '/jieba-query-producer-bounds.php';
$hardeningChecks += require __DIR__ . '/tokenizer-yield-bounds.php';
$hardeningChecks += require __DIR__ . '/extension-output-bounds.php';
$hardeningChecks += require __DIR__ . '/html-text-stream-boundary-bounds.php';

echo "Full-text search component smoke passed ({$hardeningChecks} hardening checks).\n";
