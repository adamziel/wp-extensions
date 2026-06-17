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

The initial split keeps the legacy `WP_FTS_*` global class names so existing
plugin code and tests stay compatible. A future publishing pass can add
namespaced wrappers without changing this first extraction.
