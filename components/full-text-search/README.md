# Full-Text Search Component

`wp-php-toolkit/full-text-search` is the reusable FTS engine used by the Pure
PHP FTS Indexer WordPress plugin. It is plain PHP and does not require
WordPress.

The component owns HTML text extraction, normalization, language detection,
stemming and lemmatizer-pack loading, term generation, document indexing,
BM25-style searching, snippets/highlighting helpers, storage interfaces, and
the in-memory backend plus a test/demo-only file backend for non-WordPress
callers.

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

## File Storage Scope

`WP_FTS_Storage_File` is for tests, demos, and small local indexes. It is not a
production or sizable-index backend: it keeps the full index in memory and
rewrites the full JSON document at every outer commit. Use a database-backed
storage implementation when index size, write throughput, or service
availability matters.

File storage serializes cooperating writers with a persistent `<index>.lock`
sidecar. An outer transaction acquires that lock, reloads the latest revision,
and holds the lock through commit or rollback. Commits compare the loaded file
fingerprint before replacement, increment the payload revision, fully write,
flush, and `fsync()` a same-directory temporary file, and then use a checked
atomic rename. The lock is advisory: other code must not edit or replace the
JSON file directly or remove and recreate the lock sidecar. File data is
synchronized before rename, but guarantees after sudden power loss still depend
on the host filesystem and storage stack.

Wrap bulk indexing in one outer transaction. `WP_FTS_Indexer` transactions
become nested savepoints, so the whole batch performs one JSON rewrite instead
of one rewrite per document:

```php
$storage = new WP_FTS_Storage_File(__DIR__ . '/search-index.json');
$indexer = new WP_FTS_Indexer($storage, $analyzer);

$storage->begin_transaction();
try {
    foreach ($documents as $id => $html) {
        $indexer->index_document($id, $html, ['lang' => 'en']);
    }
    $storage->commit();
} catch (Throwable $error) {
    // A failed commit keeps its rollback snapshot and lock until rollback.
    $storage->rollback();
    throw $error;
}
```

An instance exposes the snapshot it most recently loaded; read methods do not
poll for commits made by other processes. Reopen the storage for a fresh read
snapshot. A new write transaction always reloads under the lock.

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

## Authoritative Candidate Filtering

Callers with an external visibility model can pass a
`candidate_doc_ids_filter` callable. It receives the complete active candidate
ID list and must return the allowed subset. The searcher intersects that result
with the original candidates before scoring, totals, and pagination; returned
IDs cannot inject new documents. This option forces exact candidate discovery,
even when fast top-K was requested, because applying authorization after an
approximate window can produce false-empty or incomplete result pages.
Storage-specific `search_extension` callbacks own candidate discovery, ranking,
and pagination, so they cannot be combined with this option; extensions must
apply their own authorization model instead.

The initial split keeps the legacy `WP_FTS_*` global class names so existing
plugin code and tests stay compatible. A future publishing pass can add
namespaced wrappers without changing this first extraction.

Field boosts currently feed integer posting frequencies rather than a BM25F
field model. Direct callers should use positive whole-number boosts: fractional
totals are rounded during indexing, and zero or negative field values fall back
to `1`. Omit a field from `index_document_fields()` when it should not
contribute to search.
