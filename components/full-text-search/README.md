# Full-Text Search Component

`wp-php-toolkit/full-text-search` is the framework-neutral analysis and query
planning library used by the Pure PHP FTS Indexer WordPress plugin. It requires
PHP 8.1 or newer and does not require WordPress.

Load the Composer requirements before constructing an analyzer. The Unicode
normalizer polyfill and `wamania/php-stemmer` are runtime dependencies; a
missing Wamania factory stops analyzer construction instead of changing
Catalan or Dutch terms into silent no-ops.

The component owns HTML text extraction, normalization, language detection,
stemming, lemmatizer-pack loading, index-payload preparation, relational query
planning, and safe snippet generation. It deliberately does not contain a
posting-list engine or a storage implementation.

WordPress hooks, post extraction, `$wpdb` storage, schema management, queueing,
REST integration, WP-CLI commands, and Playground packaging live in the
`indexer/` adapter.

## Index Preparation

`WP_FTS_Indexer` analyzes input and returns a fixed payload for a relational
batch writer. It never reads or writes storage.

```php
$analyzer = new WP_FTS_Analyzer([
    'default_lang' => 'en',
]);
$indexer = new WP_FTS_Indexer($analyzer);

$prepared = $indexer->prepare_document_fields(42, [
    ['name' => 'title', 'text' => 'Searchable Library', 'boost' => 3.0],
    ['name' => 'content', 'text' => 'Portable full text search.', 'boost' => 1.0],
], [
    'document_lang' => 'en',
]);
```

The result contains exactly:

- `doc_id`
- `primary_lang`
- `content_hash`
- `snippet_text`
- `term_frequencies`
- `surface_frequencies`

The term and surface maps use canonical `language + separator + term` keys.
`content_hash` includes normalized fields, language, and analyzer behavior, so a
writer can skip an unchanged document before publishing replacement rows.

A framework adapter may pass an extractor as the second constructor argument
and use `prepare_post_source()` followed by `prepare_post_from_source()`. This
split lets a queue compare the source hash before it spends time on full text
analysis.

## Relational Search

`WP_FTS_Searcher` accepts only `WP_FTS_Set_Oriented_Search_Storage`. It analyzes
a query once, builds at most 12 logical groups with at most 12 alternatives in
total, and makes one bounded `search_page()` call. The backend owns prefix
resolution, visibility filters, ranking, hydration, and cursor pagination.

```php
$searcher = new WP_FTS_Searcher($storage, $analyzer);
$payload = $searcher->search('portable search', [
    'mode' => 'AND',
    'query_lang' => 'en',
    'limit' => 10,
    'include_metadata' => true,
    'include_snippets' => true,
]);
```

The public result is always a cursor page:

```php
[
    'query_lang' => 'en',
    'has_more' => false,
    'next_cursor' => null,
    'previous_cursor' => null,
    'results' => [],
]
```

Cursor pages do not expose a total. There is no offset mode and no interactive
full-count query. Current public options cover match mode, page size, language,
cursor direction, prefix matching, post type/status/date filters, page-sized
metadata and snippets, recency boost, and fixed backend explain output. Unknown
options are rejected before storage runs.

Storage rows are exact native values, not normalization inputs. Document IDs are
positive integers, scores are finite floats, metadata type/status values contain
at most 64 bytes, titles and snippet sources contain at most 20,000 bytes, and
each metadata or private canonical-row page contains at most 4 MiB. A canonical
row starts with an integer `ID` matching `doc_id`; its remaining values are
native UTF-8 strings. Duplicate document IDs and malformed, reordered, or
oversized rows fail at the component boundary.

## Snippet Output

`include_snippets` turns the bounded `snippet_text` sidecar returned with a page
row into safe HTML. `snippet_for_text()` does the same for caller-supplied text.
The component extracts visible text, escapes source bytes, and inserts only its
own `<mark>` elements when highlighting is enabled. Source tags, attributes,
and entity-decoded markup are never copied into the result.

## Multilingual Normalization And Alternatives

Document and query text is normalized to Unicode NFKC before case folding,
dialect rules, and stemming. The component directly requires the pure-PHP intl
normalizer polyfill, so canonically equivalent and compatibility forms use the
same stored keys even when the native `intl` extension is unavailable.

When one dictionary surface has several possible lemmas, preparation retains
every candidate key for recall but counts the source occurrence only once in
the weighted frequency. Query planning keeps those alternatives in one logical
group so the relational backend can choose one match instead of adding every
ambiguous interpretation to the score. A pack may contain at most 12 lemmas
for one surface across all of its shards. Full streaming validation and indexed
runtime lookup both reject candidate 13; runtime lookup never truncates an
invalid pack.

HTML is preflighted in one byte-streaming pass before either WordPress HTML
processor or the component fallback parser runs. One document may contain at
most 20,000 markup tokens, 256 nested elements, 16,384 bytes in one element tag,
128 attributes on one tag, 4,096 bytes in one complete ordinary attribute,
64 bytes in `lang`/`xml:lang`, and eight language subtags. Exceeding a boundary
raises a typed `WP_FTS_Analysis_Limit_Exceeded` before analysis continues.
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
stemmers likewise may emit at most one 4-KiB lexical run. Custom analyzer
arrays may return at most 20,000 occurrences (the relational production
path retains its stricter 12-alternative limit). Query and document rows use
one allowlisted occurrence schema with native strings, nonnegative integer
positions/ranks, and finite positive document weights; unsupported fields fail
before the array is reindexed.

Analyzer construction is bounded before it resolves pack paths: 32 configured
languages, 2,048 option nodes, 64 KiB of scalar/key data, eight array levels,
256 entries per array, 128-byte keys, and 4 KiB scalar/path values. Local
manifests are limited to 64 KiB, 2,048 nodes, eight levels, 64 runtime files,
256 lookup blocks per file, and 8,192 lookup blocks per pack. Configured packs
collectively retain at most 128 runtime files and 16,384 lookup blocks; lookup
headers stop at 64 KiB. One pack may retain at most 16 MiB of physical
runtime-plus-lookup files; all configured packs share a 32 MiB physical
ceiling. Indexed blocks decode at most 16 KiB, namespaced term keys stop at 255
bytes, and runtime rows/comments stop at 4 KiB. Every multi-shard pack must
declare complete normalized surface
ranges that are strictly ordered and non-overlapping; validation rejects unsafe
ranges before runtime files are read, and lookup binary-selects at most one
shard. A single-shard pack may omit ranges. Every runtime shard is indexed gzip
with a validated lookup sidecar, and runtime lookup inflates only the selected
bounded block. There is no plain-file, whole-gzip, eager-map, or linear-scan
runtime path. Over-limit arrays,
language-map iterators, paths, compressed expansions, and callback captures throw
`WP_FTS_Analyzer_Config_Limit_Exceeded`; they are not partially loaded or
silently truncated.

The component repository commits a 329,972-byte, 11,783-range lookup index
keyed by first Unicode codepoint. An initialized source checkout supplies the
pinned dictionary during development. The WordPress release builder verifies
that checkout and stages `manifest.json`, `dict.txt`, its MIT `LICENSE`, and
`dict.idx` under the curated runtime path; it does not ship the raw checkout. A
standalone component copy without either the curated runtime dictionary or
initialized source checkout can use deterministic CJK fallback n-grams by
leaving the Jieba entry absent or setting it to native `false`; explicit
enablement fails when the pinned runtime cannot load.

The runtime manifest owns the upstream repository and commit plus the
dictionary, license, and lookup identities. The lookup header binds the pinned
dictionary digest and byte size, and every range carries its own 128-bit
SHA-256 prefix. Construction therefore hashes the compact index rather than
rereading all 5,071,852 dictionary bytes; each source range is verified when
used. The segmenter option has one form: `true` enables this pinned dictionary,
while `false` disables it. Custom dictionary paths and per-request
lookup construction are not supported.

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

Dictionary readers accept at most one 8-KiB row; byte 8,193 raises
`jieba_dictionary_line_bytes` before an oversized row is materialized. Complete
segment results are additionally memoized in an LRU of at most 256 runs, 4,096
result tokens, and 256 KiB of run/token bytes; that eviction changes only token
recomputation because prefix ranges remain resident.

## Backend Explain Output

When `explain` is true, the relational backend must return exactly these fields
in order: `storage`, `logical_group_count`, `resolved_alternatives`,
`anchor_group`, `prefix_range`, `prefix_strategy`, `query_statements`,
`interactive_total`, `recency_boost`, and `canonical_page_bytes`. Storage is
always `set_oriented`; the interactive total is always `unknown`; prefix
strategy is `none`, `surface_range`, or `candidate_first`; and statement and
byte counts stay inside the relational limits. `recency_boost` contains exactly
`enabled`, `strength`, `half_life_days`, and `scoring_now_gmt` with native types.
There are no per-result diagnostic rows. The searcher validates and passes this
fixed map through without running posting-list reads or rebuilding a second
query plan.

The component exposes `WP_FTS_*` global class names.

Field boosts become positive integer term frequencies in the prepared writer
payload rather than a separate BM25F field model. Boosts must be native whole
numbers from 1 through 100.
