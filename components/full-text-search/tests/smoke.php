<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$analyzer = new WP_FTS_Analyzer([
    'auto_detect_language' => false,
    'enable_stemming' => false,
    'default_lang' => 'en',
]);
$prepared = (new WP_FTS_Indexer($analyzer))->prepare_document_fields(1, [
    ['name' => 'title', 'text' => 'Searchable Library', 'boost' => 3.0],
    ['name' => 'content', 'text' => 'Portable full text search works.', 'boost' => 1.0],
], ['document_lang' => 'en']);

if (($prepared['term_frequencies'][WP_FTS_TermNamespace::namespace_term('en', 'portable')] ?? 0) !== 1) {
    fwrite(STDERR, "Expected index preparation to emit the portable term.\n");
    exit(1);
}

$storage = new WP_FTS_Test_Set_Oriented_Search_Storage([
    'results' => [['doc_id' => 1, 'score' => 4.25]],
    'has_more' => false,
    'next_cursor' => null,
    'previous_cursor' => null,
]);
$payload = (new WP_FTS_Searcher($storage, $analyzer))->search(
    'portable search',
    ['mode' => 'AND', 'query_lang' => 'en']
);
if (($payload['results'][0]['doc_id'] ?? null) !== 1 || $storage->call_count !== 1) {
    fwrite(STDERR, "Expected relational search to return document 1 in one storage call.\n");
    exit(1);
}

$hardeningChecks = require __DIR__ . '/coverage-hardening.php';
$hardeningChecks += require __DIR__ . '/constructor-options.php';
$hardeningChecks += require __DIR__ . '/lemma-pack-limits.php';
$hardeningChecks += require __DIR__ . '/jieba-indexed-multi-run.php';
$hardeningChecks += require __DIR__ . '/jieba-query-producer-bounds.php';
$hardeningChecks += require __DIR__ . '/tokenizer-yield-bounds.php';
$hardeningChecks += require __DIR__ . '/extension-output-bounds.php';
$hardeningChecks += require __DIR__ . '/strict-extension-contracts.php';
$hardeningChecks += require __DIR__ . '/strict-search-output.php';
$hardeningChecks += require __DIR__ . '/html-text-stream-boundary-bounds.php';

echo "Full-text search component smoke passed ({$hardeningChecks} hardening checks).\n";
