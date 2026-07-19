# Full-Text Search Component

`wp-php-toolkit/full-text-search` is the reusable FTS engine used by the Pure
PHP FTS Indexer WordPress plugin. It is plain PHP and does not require
WordPress.

The component owns HTML text extraction, normalization, language detection,
stemming and lemmatizer-pack loading, term generation, document indexing,
set-oriented search planning, snippets/highlighting helpers, storage
interfaces, and legacy in-memory/file fixtures for tests and local demos.

It does not own WordPress hooks, plugin activation, wp-admin UI, WP-CLI commands,
post extraction, `$wpdb`/MySQL storage, REST integration, or Playground
packaging. Those stay in the `indexer/` plugin adapter.

## Legacy local fixture usage

This example is deliberately not the WordPress production path. The
in-memory/file backends materialize posting lists in PHP and exist only for
component fixtures and tiny local demos. WordPress constructs search through
`WP_FTS_Searcher::for_set_oriented_storage()`; that factory rejects either
legacy backend.

```php
require_once __DIR__ . '/vendor/autoload.php';

$analyzer = new WP_FTS_Analyzer(['default_lang' => 'en']);
$storage = new WP_FTS_Storage_InMemory();
$indexer = new WP_FTS_Indexer($storage, $analyzer);

$indexer->index_document(1, '<h1>Hello search</h1><p>Portable FTS.</p>', [
    'lang' => 'en',
]);

$searcher = new WP_FTS_Searcher($storage, $analyzer);
$results = $searcher->search('portable search', [
    'lang' => 'en',
    'include_snippets' => true,
]);

echo $results[0]['snippet'];
```

Everything below that discusses candidate caps, PHP BM25, full posting lists,
exact totals, or callback-based visibility describes this legacy local fixture
API only. The WordPress plugin uses the fail-closed set-oriented factory and
does not expose those modes on any production surface.

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

`index_document()` stores a bounded plain-text snippet source automatically.
Callers may override it with `metadata.search_text`; field-oriented integrations
can continue to use `index_document_fields()` and their own metadata.

## Multilingual Normalization And Alternatives

Document and query text is normalized to Unicode NFKC before case folding,
dialect rules, and stemming. The component directly requires the pure-PHP intl
normalizer polyfill, so canonically equivalent and compatibility forms use the
same stored keys even when the native `intl` extension is unavailable.

When one dictionary surface has several possible lemmas, every candidate keeps
a posting for recall, but the source token contributes only once to document
length. Search selects the best-ranked, strongest BM25 candidate inside each
logical query-token group instead of adding every ambiguous interpretation to
the score. A pack may contain at most 12 lemmas for one surface across all of
its shards. Full validation, eager fixture loading, and indexed runtime lookup
all reject candidate 13; runtime lookup never truncates an invalid pack.

HTML is preflighted in one byte-streaming pass before either WordPress HTML
processor or the component fallback parser runs. One document may contain at
most 20,000 markup tokens, 256 nested elements, 16,384 bytes in one element tag,
128 attributes on one tag, 4,096 bytes in one complete ordinary attribute,
64 bytes in `lang`/`xml:lang`, and eight language subtags. Exceeding a boundary
raises a typed `WP_FTS_Analysis_Limit_Exceeded` before storage is consulted.
Caller-provided HTML processors cross those same boundaries: their complete
token stream is capped at 40,001 tokens, the active element-state stack at 256
rows below the processor's implicit fragment roots, tags at 16 KiB, language
attributes at 64 bytes, and token-type names at 64 bytes. Aggregate tag,
language, and text output shares a 2-MiB envelope; token-type output has a
separate 2-MiB aggregate envelope. Processors must expose the WordPress 6.6
depth and closer event API; earlier or partial implementations use the fallback
parser. The analyzer never requests breadcrumb snapshots. Each opener pushes
one scalar state row and each closer pops it, while inline ancestor sequences
are interned once per request and segments retain one integer path ID. Valid
source depth and token limits therefore consume linear rather than
multiplicative time and storage. Provider output is measured
before trim, uppercase, coalescing, or Unicode-normalization copies. Custom CJK tokenizers, token normalizers, and
stemmers likewise may emit at most one 4-KiB lexical run. Legacy component
analyzer arrays may return at most 20,000 occurrences (the relational production
path retains its stricter 12-alternative limit), with scalar fields checked
before the array is reindexed.

Analyzer construction is bounded before it resolves pack paths: 32 configured
languages, 2,048 option nodes, 64 KiB of scalar/key data, eight array levels,
256 entries per array, 128-byte keys, 4 KiB scalar/path values, and 32 fields in
one pack option. Local manifests are limited to 64 KiB, 2,048 nodes, eight
levels, 64 runtime files, 256 lookup blocks per file, and 8,192 lookup blocks
per pack. Configured packs collectively retain at most 128 runtime files and
16,384 lookup blocks; lookup headers stop at 64 KiB. One pack may retain at
most 16 MiB of physical runtime-plus-lookup files; all configured packs share
a 32 MiB physical ceiling. Distinct fixture packs that are eligible for eager
loading also share one 50,000-declared-row and 8-MiB decoded-runtime ceiling.
Plain-runtime bytes are checked from their manifests before any eager map is
constructed; compressed candidates consume the same decoded budget during
their bounded validation scan. Indexed blocks decode at most 16 KiB, namespaced
term keys stop at 255 bytes, and runtime rows/comments stop at 4 KiB. Every
multi-shard pack must declare complete normalized surface
ranges that are strictly ordered and non-overlapping; validation rejects unsafe
ranges before runtime files are read, and lookup binary-selects at most one
shard. A single-shard pack may omit ranges. Only a `fixture_only` pack with at
most 50,000 rows and 8 MiB of decoded runtime data may omit lookup sidecars; it
is fully validated and loaded once into a bounded eager map. Every other shard
must be indexed gzip with a validated lookup sidecar, and runtime lookup inflates
only the selected bounded block.
There is no non-fixture whole-gzip or linear-scan fallback. Over-limit arrays,
language-map iterators, paths, compressed expansions, and callback captures throw
`WP_FTS_Analyzer_Config_Limit_Exceeded`; they are not partially loaded or
silently truncated.

The component repository commits a 329,972-byte, 11,783-range lookup index
keyed by first Unicode codepoint. An initialized source checkout supplies the
pinned dictionary during development. The WordPress release builder verifies
that checkout and stages only `dict.txt`, its MIT `LICENSE`, and `dict.idx`
under the curated runtime path; it does not ship the raw checkout. A standalone
component copy without either the curated runtime dictionary or initialized
source checkout makes the default Jieba option unavailable and continues with
deterministic CJK fallback n-grams.

The index digest is compiled into the segmenter, its header binds the pinned
source digest and byte size, and every range carries its own 128-bit SHA-256
prefix. Pinned construction therefore hashes the compact index rather than
rereading all 5,071,852 dictionary bytes; each source range is verified when
used. Source-only custom dictionaries are supported only by explicit fixture
configuration. They retain eager complete-source hashing and build a packed
6.38-MiB Unicode head/count state plus 12-byte range records in one complete
scan; records spill to a temporary stream above 1 MiB. Production custom
dictionaries are not currently supported. A future production custom-pack
contract would need an offline-built, source-bound attested sidecar rather than
per-request source hashing and indexing.

`php tools/build-jieba-lookup-index.php --check` rebuilds the v2 sidecar from
the pinned source and compares it byte-for-byte with the committed file. Run
the command without `--check` only when intentionally updating that source.

Every requested first-codepoint range is loaded at most once per segmenter
instance. Populated prefixes use compact word-membership and word-length maps;
a 136-KiB Unicode bitset remembers both populated and empty ranges without an
evictable prefix LRU. The complete pinned definition contains 337,461 eligible
rows and 3,013,799 candidate-word bytes. Of those, 337,399 rows and 3,013,489
bytes across 5,628 Han prefixes are reachable through `LanguagePipeline`; all
fit below the 350,000-row and 8-MiB complete-cache bounds. A maximum accepted
4,095-byte Han run can cover 285,075 rows without changing segmentation or
triggering an aggregate run rejection.

Fixture dictionaries are admitted during their one dynamic-index scan only if
their complete eligible set fits the same 350,000-row and 8-MiB cache, with at
most 5,000 candidates per prefix. Accepted fixtures therefore have no eviction
or alternating-prefix reread path. Rejected admission is memoized, so retrying
the same instance is constant work. Dictionary readers accept at most one
8-KiB row; byte 8,193 raises `jieba_dictionary_line_bytes` before an oversized
row is materialized. Complete segment results are additionally memoized in an
LRU of at most 256 runs, 4,096 result tokens, and 256 KiB of run/token bytes;
that eviction changes only token recomputation because prefix ranges remain
resident.

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

Bounded authorization layers can instead pass an `explain_doc_ids_filter`
callable. It receives the ranked page's document IDs and returns the subset
eligible for document-level explain reads. The filter does not change ranking,
totals, pagination, or retrieval mode.

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
