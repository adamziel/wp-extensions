# Full-Text Search Component

`wp-php-toolkit/full-text-search` is the reusable FTS engine used by the Pure
PHP FTS Indexer WordPress plugin. It is plain PHP and does not require
WordPress.

The component owns HTML text extraction, normalization, language detection,
stemming and lemmatizer-pack loading, term generation, document indexing,
BM25-style searching, snippets/highlighting helpers, storage interfaces, and
the in-memory/file storage backends used by tests and non-WordPress callers.

It does not own WordPress hooks, plugin activation, wp-admin UI, WP-CLI commands,
post extraction, `$wpdb`/MySQL storage, REST integration, or Playground
packaging. Those stay in the `indexer/` plugin adapter.

## Minimal Usage

```php
require_once __DIR__ . '/vendor/autoload.php';

$analyzer = new WP_FTS_Analyzer(['default_lang' => 'en']);
$storage = new WP_FTS_Storage_InMemory();
$indexer = new WP_FTS_Indexer($storage, $analyzer);

$indexer->index_document(1, '<h1>Hello search</h1><p>Portable FTS.</p>', [
    'lang' => 'en',
]);

$searcher = new WP_FTS_Searcher($storage, $analyzer);
$results = $searcher->search('portable search', ['lang' => 'en']);
```

## Retrieval Accuracy

Search considers every matching candidate by default. This keeps ranking and
totals exact regardless of document-id order.

The legacy `fast_top_k` and `approximate_top_k` options explicitly select a
document-id candidate cap. That mode is not a ranking-aware top-K algorithm and
may omit stronger documents beyond the cap. It always returns a payload with
`retrieval_mode: candidate_capped`, `total_is_exact: false`,
`results_may_be_incomplete: true`, and the applied `candidate_cap`, even when
`include_total` is omitted. Incompleteness here means matching documents may be
omitted before normal limit/offset pagination.

## Snippet Output

`include_snippets` and `snippet_for_text()` return safe HTML. The component
extracts visible text, escapes every source byte, and inserts only its own
`<mark>` elements when highlighting is enabled. Original tags, attributes, and
entity-decoded markup are never copied into the result, so callers can render
the returned snippet without maintaining a second source-markup allowlist.

## Search Explain Payloads

`WP_FTS_Searcher::search()` keeps the legacy list return shape by default.
Callers that already request `include_total` can add `explain` or `debug` to
receive a bounded diagnostics payload:

```php
$payload = $searcher->search('portable search', [
    'lang' => 'en',
    'include_total' => true,
    'explain' => true,
]);
```

The `explain` payload reports the storage backend, query surfaces with their
analyzed storage terms/keys, prefix expansion count, retrieval-mode decision,
candidate/scoring shape, total accuracy, and bounded per-result match reasons
for the returned page. Set
`explain_result_matches` to `false` when a caller needs plan/scoring diagnostics
but must defer document-term lookups.

The initial split keeps the legacy `WP_FTS_*` global class names so existing
plugin code and tests stay compatible. A future publishing pass can add
namespaced wrappers without changing this first extraction.
