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

The `explain` payload reports the storage backend, analyzed query plan, prefix
expansion count, fast-mode decision, candidate/scoring shape, total accuracy,
and bounded per-result match reasons for the returned page. Set
`explain_result_matches` to `false` when a caller needs plan/scoring diagnostics
but must defer document-term lookups.

The initial split keeps the legacy `WP_FTS_*` global class names so existing
plugin code and tests stay compatible. A future publishing pass can add
namespaced wrappers without changing this first extraction.
