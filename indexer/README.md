# Language FTS

[![Try in Playground](https://github.com/WordPress/action-wp-playground-pr-preview/raw/main/assets/playground-preview-button.svg)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/adamziel/wp-extensions/main/indexer/playground/blueprint.json)

Language FTS is a WordPress plugin that builds a local full-text search index
for WordPress content. It stores derived index data in WordPress database
tables, keeps that data current through bounded WordPress lifecycle and queue
workflows, and exposes practical admin, WP-CLI, optional REST/search, and
diagnostic surfaces for understanding how search behaves on a site.

It is intended for careful evaluation and controlled rollout. Try it in
Playground or staging first, check results with your own content, keep backups
and a rollback path, and monitor indexing, cron, database load, and search
quality before enabling search replacement for visitors. Feedback is welcome on
a best-effort basis, with priority for safety issues, install or activation
failures, data/index corruption risks, fatal errors, security reports, and
clear reproducible bugs.

The plugin is now a thin WordPress adapter around the reusable
`wp-php-toolkit/full-text-search` Composer component in
`components/full-text-search/`. The component owns the framework-neutral FTS
engine; this plugin owns WordPress hooks, post extraction, relational database
storage, admin UI, WP-CLI, REST/search integration, and Playground packaging.

The Playground preview downloads the version-pinned core release ZIP, verifies
its SHA-256 digest before activation, and opens the admin-only Settings >
Full-Text Search Sandbox tab. The sandbox searches content already present in
the full-text index and never creates demo posts or hidden sample content. It
shows indexed posts with pagination, lets you run language-aware searches, and
indexes new or updated published posts when they are saved. Playground is
useful for trying the workflow quickly; production suitability still depends
on the site, host, database, cron, cache, content, traffic, and plugin/theme
mix.

The plugin does not use MySQL `FULLTEXT`, replaces normal front-end main-query
search and eligible wp-admin Posts list searches with ranked FTS results by
default, and treats the index as rebuildable data derived from WordPress
content.

Settings > Full-Text Search provides Health, Settings, Sandbox, Indexed content,
and Analyzer packs tabs. It manages the indexed post types, automatic indexing,
search replacement surfaces, search-provider compatibility, highlighting,
snippets, prefix matching, optional public REST search, result limits, field
ranking weights, an optional recency ranking boost, single-plan language routing,
schema status, and a Health tab schema repair action. The Analyzer packs tab can
enable or disable bundled runtime lemma packs when PHP gzip support is
available; custom analyzer-pack paths and custom field selection still use the
documented options and filters.

## Quickstart

Install the published self-contained core ZIP. It includes the production
Composer dependencies and reusable FTS component that WordPress needs at
activation. The version and digest below match the package used by the public
Playground preview.

```sh
curl -fL https://github.com/adamziel/wp-extensions/releases/download/language-fts-v0.1.12/language-fts-core.zip \
  -o language-fts-core.zip
echo '4a7baff284b74d7fc72d071f589730269748c66bb82f68b0ce426739f57bdc7f  language-fts-core.zip' \
  | shasum -a 256 --check \
  && wp plugin install ./language-fts-core.zip --activate
rm language-fts-core.zip
```

Do not copy `indexer/` out of a source checkout and run Composer there. Its
development-only Composer path repository points to the adjacent
`components/full-text-search/` directory, which is not present after that copy.
Developers working in the monorepo can build the same standalone package with
`php indexer/tools/build-release-zip.php` as described in
[`docs/release-packaging.md`](docs/release-packaging.md).
Release ZIPs contain only the importer and documented pack-management tools
listed there. Composer development scripts and every other `tools/` command
require a complete source checkout.

Activation creates or repairs the `fts_*` tables and schedules the bounded
runtime queue processor. Reindex stores one filtered durable scope and returns;
it never selects or indexes the matching posts in the CLI request. WP-Cron
drains that scope in bounded worker passes:

```sh
wp fts reindex --post_type=post --format=json
```

When an operator cannot wait for WP-Cron, run one bounded worker pass explicitly
and repeat only as needed:

```sh
wp fts process-batch --batch_size=100 --time_budget=20 --format=json
```

The default post status scope for `wp fts reindex` is
`publish,draft,pending,future,private`, matching the admin Posts list search
surface for every configured searchable post type. Normal front-end search
replacement still returns published content only; use
`--post_status=publish` for a public-only backfill.

Run a search with the language you expect:

```sh
wp fts search "example query" --lang=en --limit=5
```

The output includes WordPress post IDs, deterministic relational scores,
cursor-page state, stored metadata, and optional snippets. It does not include
a total. `score` is relative to the current query; it is not a percentage and
should not be compared across unrelated queries.

## Ranking Field Weights

Settings > Full-Text Search > Settings includes ranking weights for the
WordPress post fields extracted into the index: title, main content, excerpt,
taxonomy terms, and selected custom fields. The controls
accept whole numbers from `1` through `100`; higher numbers make matches in that
field count more strongly during ranking. To exclude a field entirely, remove
it with the `wp_fts_post_index_fields` filter instead of assigning a zero weight.

The defaults match the extractor defaults: title `5.0`, content `1.0`, excerpt
`2.0`, taxonomy terms `2.0`, and selected custom fields `1.0`. These are
index-time weights stored with indexed content, so
changed weights fully affect existing content only after it is reindexed.
Saving changed weights marks stale reindex debt in Health/status; it does not
index content during the settings save.

The same Ranking weights section also includes an optional recent post boost.
It is off by default (`0`). When enabled, query-time ranking gives newer posts a
small bounded lift using indexed `post_date_gmt` metadata. Changing the strength
or half-life does not require reindexing when the date metadata is already in
the index. Missing, empty, or invalid dates are ignored safely, and search
explain diagnostics report the bounded relational plan used for the page.

## Word Beginnings

Word beginnings can be enabled or disabled from Settings > Full-Text Search.
When enabled, exact analyzer and lemma matches still rank before prefix-only
alternatives. Prefix matching applies only to the final source word and stays
as one complete indexed `kind=1` normalized-surface range inside SQL. If that
final source word is filtered from exact analysis, prefix matching is disabled
rather than silently falling back to an earlier word. The saved
`prefix_min_length` default is `4`; lowering it broadens matches for shorter
searched words, which can be slower or noisier.

The saved minimum applies to front-end replacement, wp-admin Posts search
replacement, the Sandbox, and `WP_FTS_Plugin::search()`. `wp fts search`
accepts `--prefix_matching` and `--prefix_min_length`.
There is no relational completion cap: truncating the range would make valid
matches depend on vocabulary order, while enumerating it would recreate the
fanout this backend is designed to avoid.

Public REST search is a separate opt-in setting and is absent by default. Its
word-beginning expansion is also off by default and cannot be enabled by a
request parameter. See [Operations](docs/operations.md#public-rest-search) for
the fixed query shape and deployment guidance.

## Architecture

A useful way to think about Language FTS is as a rebuildable search view beside
WordPress. WordPress posts remain the source of truth. The FTS tables contain
only the dictionary, postings, bounded result sidecars, and durable work needed
to find one ranked page. If the index is lost, it can be rebuilt from WordPress
content.

There is deliberately one production search architecture. The component no
longer contains a file-backed engine or a PHP posting-list ranker, and the
plugin does not carry a migration ladder for unreleased schema prototypes. An
incompatible derived table is replaced, not translated indefinitely; content
is recovered by reindexing from WordPress.

### Responsibilities

The design has three layers with fairly strict boundaries:

| Layer | Responsibility |
| --- | --- |
| Reusable component | Normalizes and analyzes bounded text, prepares storage-ready documents, turns a query into bounded logical groups, and validates the returned page. |
| WordPress adapter | Decides which WordPress queries are safe to replace, extracts canonical post data, records content changes, runs the background worker, and provides admin, REST, and WP-CLI surfaces. |
| Relational storage | Owns the four tables, transactional index publication, dictionary planning, candidate discovery, visibility, ranking, cursor checks, and page hydration. |

The analyzer does not know about SQL, and the searcher never asks storage to
return a complete posting list. Storage receives a bounded query plan and
returns a bounded page.

### The four tables

| Table | What it stores |
| --- | --- |
| `fts_terms` | One binary-stable dictionary row per language, identity kind, and normalized term, plus document frequency. |
| `fts_postings` | One compact `(term_id, post_id)` row with precomputed field impact and indexes for both term-first and post-first work. |
| `fts_documents` | One bounded derived row per indexed post: primary language, content hash, snippet source, and indexing time. A slim post-ID index answers ranking-time existence probes without reading snippet-bearing rows. Canonical visibility and post metadata stay in WordPress. |
| `fts_work` | Post generations, reconciliation scopes, claims, leases, retries, failure codes, and the search epoch used to invalidate stale cursors. |

Lexical analyzer identities use `kind=0`. Word-beginning search uses one
normalized identity per distinct source surface as `kind=1`; it does not store
every possible prefix. Prefix lookup is therefore one indexed dictionary range
rather than a PHP loop over completions.

### From a post change to searchable data

1. A WordPress content change advances a durable generation in `fts_work`.
   Search immediately excludes that post's old derived row, so stale content is
   not presented as current.
2. The foreground request finishes without analyzing the post. WP-Cron, or one
   explicit bounded worker pass, later claims at most 100 posts and reads a
   bounded canonical snapshot.
3. The extractor builds weighted fields. The analyzer normalizes visible text,
   applies the selected language pipeline, and emits bounded lexical and surface
   frequencies plus a content hash and snippet source.
4. The relational writer measures the complete old-plus-new posting frontier
   before opening its transaction. Oversized valid work is split; one document
   that violates a hard limit is rejected without making the rest of the batch
   opaque.
5. On MySQL and MariaDB, posting replacement, dictionary-frequency changes,
   exact-generation acknowledgement, and cursor-epoch advancement are
   published through the same transaction boundary.

If another save advances the generation while a worker is analyzing, that
worker cannot erase the newer work item or make its older snapshot visible.
The newer generation stays dirty until a later worker publishes it.

### From a query to one page

1. The analyzer runs once and groups exact and morphological alternatives by
   source word. Optional prefix matching applies only to the final source word.
2. One planning statement resolves exact dictionary identities, reads their
   document frequencies, measures the one prefix range when present, and reads
   the current cursor epoch.
3. One ranking statement chooses the bounded relational shape, applies `OR` or
   `AND` membership, filters current WordPress visibility and dirty generations,
   scores the candidates, orders them, and asks for one lookahead row. Typed
   searches use the plugin-owned WordPress scope index as a covering visibility
   and date-ordering path, so broad ranking does not fetch complete post rows.
4. When metadata or snippets are requested, one final statement hydrates only
   the returned page. Snippet highlighting also stays page-sized.

A successful relational page therefore uses planning, ranking, and optional
hydration rather than one query per term or completion. Pages contain at most
50 results and use signed search-after cursors. They deliberately do not run an
exhaustive count.

### Upsides and strengths

- **Bounded PHP work.** Complete posting lists, complete result sets, and prefix
  completion lists never cross into PHP. Query analysis, result hydration, and
  worker batches all have explicit size limits.
- **Current WordPress truth.** Search visibility and returned post metadata come
  from canonical WordPress rows instead of a second copy that can drift.
- **Safe publication under concurrent saves.** Dirty-row exclusion and exact
  generations prefer temporarily omitting a changed post over showing stale
  content or letting an obsolete worker clear newer work.
- **One production path.** The component, plugin, tests, and operational tools
  describe the same set-oriented engine instead of maintaining a second
  posting-list implementation for compatibility.
- **Rebuildable state.** The index can be reset or replaced without treating it
  as the only copy of user content.
- **Predictable request shape.** The number of search statements and the amount
  of application memory are bounded even when the matching set is large.

### Downsides and limitations

- **Indexing is asynchronous.** A newly changed post may be absent from FTS
  results until the worker catches up. Reliable WP-Cron, or an external runner,
  is part of operating the plugin.
- **Bounded PHP does not mean constant database work.** A common `OR` term or a
  broad final prefix still makes the database examine work proportional to the
  matching postings. This is aimed at small and medium WordPress sites, not at
  replacing a dedicated search cluster for very large or high-traffic corpora.
- **The query model is intentionally narrow.** There are no phrases, positions,
  facets, typo tolerance, cross-language result merging, or query-time synonyms.
  Unsupported WordPress query shapes stay with core search.
- **Pagination favors bounded work.** Callers get adjacent cursors and
  `has_more`, not exact totals, deep offsets, or arbitrary numbered pages.
- **The index has a write and storage cost.** Every indexed term creates derived
  dictionary/posting work. The slim document visibility index and wider
  WordPress scope index consume additional space and must be maintained on
  writes; in return, broad ranking avoids reading content and snippet rows.
- **Concurrency has a deployment contract.** Every PHP process sharing the
  database must also see the same stable lock-file inode with working POSIX
  `flock()` behavior. Node-local lock directories are not supported.
- **SQLite is a preview path.** The same relational design supports a
  single-request Playground smoke, but production concurrency is validated on
  MySQL and MariaDB.
- **Current-schema-only means reindexing after incompatible changes.** This
  keeps unreleased compatibility code out of the runtime, but it trades an
  in-place migration for a deliberate rebuild of derived data.

This architecture is a good fit when keeping search local to the WordPress
database, bounding PHP memory, and preserving WordPress visibility are more
important than exact totals or advanced search features. A dedicated search
service is the better fit when broad-query latency, horizontal scale, facets,
typo correction, or richer ranking controls are primary requirements.

The main classes line up with that model:

- `wp-php-toolkit/full-text-search` provides the analyzer, term generation,
  relational storage contract, `WP_FTS_Indexer`, and `WP_FTS_Searcher`.
- WordPress activation, post-save/status/delete hooks, cron, optional REST, and WP-CLI
  live in the plugin adapter and wire WordPress posts into the component.
- `WP_FTS_PostContentExtractor` extracts title, content, excerpt, taxonomy terms,
  and configured custom fields from the worker's authoritative attached
  snapshot into weighted fields plus a bounded saved-content snippet source.
- `WP_FTS_Analyzer` strips non-visible HTML, normalizes and tokenizes text,
  routes language gaps, and stems or lemmatizes through the language pipeline.
- Terms are stored under language namespaces and query occurrences use one
  primary language plan; enabling more packs does not add search passes.
- MySQL keeps a dictionary, compact row postings with precomputed impact,
  bounded document sidecars, and a durable generation-fenced work table. Each
  document stores analyzed lexical identities as `kind=0` and one complete
  normalized identity per distinct source surface as `kind=1`; it does not
  materialize every proper prefix.
- Search analyzes once, plans once, ranks once in SQL, and optionally hydrates
  one page. Current WordPress visibility and dirty work are applied before its
  score order and limit.
- MySQL/MariaDB storage is the supported WordPress production backend. The same
  relational code has a `$wpdb`-compatible SQLite path for a single-request
  WordPress Playground smoke; multi-connection generation-CAS interleavings are
  validated only on the supported production database families.

The index is derived state. Rebuild it after content imports, analyzer changes,
language-routing changes, or environment moves where the FTS tables were not
restored with WordPress content.

When index-time settings change, the plugin records stale reindex debt instead
of rewriting content during the settings request. Field ranking weights,
indexed content scope, and stored runtime analyzer-pack selections all
participate in the current index profile shown on the Health tab and in
`wp fts status --format=json`. Health's "Index the next batch now" action and
`wp fts process-batch` process queued updates first, missing eligible content
next, and stale existing rows with any remaining batch/time budget. The stale
cursor is tied to the current index profile and restarts if that profile changes
before completion. Each sweep records its highest retained document ID so posts
indexed after the sweep begins are handled once by their queue/backfill path
rather than extending the sweep indefinitely. A scope change reconciles every
retained live index row, not only rows in the new scope, so removed post types
and deleted source posts are physically removed. Activation also starts this retained-row
reconciliation whenever index data already exists. This covers content changed
while the plugin was inactive. Deactivation retains the derived index;
uninstall is the explicit destructive boundary and removes current and
reset-generation FTS tables. Before the DROP, uninstall stores one
non-autoloaded, one-byte fence
under the shared writer lease and retains it after success or partial failure.
Preloaded cron, schema-repair, save-hook, and scheduling callbacks remain inert
behind that fence. Installing the ZIP again while inactive does not remove it;
only explicit site or network activation clears each site's fence under a
writer lease, repairs the four-table schema, and queues reconciliation.

The same Health/status surfaces include a read-only queue processor schedule.
`scheduled` means WordPress has a `wp_fts_process_index_queue` event waiting;
`missing` means pending queue, backfill, or stale reindex work exists but no
queue processor event is scheduled. In that case, use the Health tab's queue
processor control or `wp fts schedule-queue` to restore the future background
event, then check WP-Cron. Schedule recovery only schedules a later background
run; it does not index content immediately. `wp fts process-batch
--batch_size=100 --time_budget=20` remains the manual one-pass fallback while
the cron problem is investigated. `not_needed` means no pending indexing work
was detected, and `unavailable` means the current context does not expose
WordPress cron inspection helpers.

Health/status also include a read-only `cron_runner` diagnostic. It reports
`traffic_triggered` when regular WordPress traffic can start WP-Cron,
`external_required` when `DISABLE_WP_CRON` is enabled, and `unknown` when the
runner mode cannot be confirmed. If pending work exists and
`cron_runner.status=external_required`, a scheduled queue event alone is not
enough; configure a host/system cron trigger for `wp-cron.php` or run bounded
manual batches such as `wp fts process-batch --batch_size=100 --time_budget=20`
while cron is fixed.

Health/status also include a sanitized shared writer lock diagnostic. `none`
means no index writer lock is held, `active` means another writer is currently
preventing overlap, and `expired` means a stale lock payload remains and will be
replaced automatically by the next indexing writer attempt. Expired locks that
recur usually indicate interrupted or fatal indexing jobs; inspect latest batch
and failure diagnostics rather than force-unlocking. This slice intentionally
does not provide a force-unlock control, because deleting an active lock can
allow overlapping index writes.

The Health tab reports stored schema/readiness state without inspecting physical
tables. Explicit support snapshots and `wp fts diagnose` add bounded read-only
physical verification; the repair button runs the same idempotent repair and
verification path as `wp fts repair`, and the new version is stored only after
the physical contract passes. Repair touches schema and table definitions only
and does not index content or create sample posts. Network activation provisions
the current site and starts a cursor-driven cron chain that repairs exactly one
existing site per event; new sites use the same provisioning path.

## Feature Summary

| Area | Current support |
| --- | --- |
| Indexing | Builds derived `fts_*` tables from persisted WordPress post fields: title, content, excerpt, batch-preloaded taxonomy terms, selected custom fields, field boosts, and a bounded saved-content snippet source. |
| Lifecycle updates | Activation repairs schema, WP-Cron drains bounded runtime work, save/status/taxonomy/selected-meta hooks coalesce durable generations, scope changes reconcile in keyset batches, documents that leave the corpus are physically removed, and `wp fts reindex` rebuilds through the same worker. |
| Language routing | Terms are stored in language namespaces. Explicit `--lang`, the batch-preloaded wp-admin `FTS Language` field, set-oriented Polylang/WPML assignment snapshots, and HTML `lang`/`xml:lang` scopes route worker content before conservative detector fallback. No per-post multilingual API runs in the worker loop. |
| Search | Exact `OR`/`AND`, final-word prefix ranges, morphology, field impact, optional recency, and signed adjacent cursors use at most planning, ranking, and page-hydration statements. Current canonical visibility and pending work are filtered before `LIMIT`; totals remain unknown. |
| Snippets | Search can return snippets from bounded content-only sidecars, with analyzer-aware highlighting performed for the returned page only. |
| Surfaces | WP-CLI is the main operational surface. The plugin also provides an explicitly enabled REST search helper, PHP search helper, front-end main-query replacement, eligible wp-admin Posts list replacement, and admin-only Settings > Full-Text Search tabs used by the Playground preview. |
| Diagnostics | Request-level FTS traces are available to authorized/debug contexts through Debug Bar when installed, or on the Health tab fallback. Their bounded relational explain reports storage, logical groups and resolved alternatives, the selected AND anchor, final-prefix range use, statement count, cursor state, and recency settings without a second result-posting pass. Traces also include performance-budget status, a bounded `posts_pre_query` hook pipeline around Language FTS, and redacted SQL summaries when the environment already collects `$wpdb->queries`; they are request-local rather than persistent logs. |

## Exact Relational Pages

The WordPress/MySQL path never materializes per-term posting collections in PHP
and has one relational execution mode. One dictionary statement resolves exact alternatives and,
for a final-word prefix, sums `doc_freq` across one surface range without
reading postings or returning completions to PHP. One set-oriented statement
ranks exact membership. Multi-group prefix `AND` compares that range cost with
the resolved exact groups and anchors the cheapest logical group. Exact groups
use indexed candidate/key probes; a selected prefix anchor streams its matching
postings and probes the other groups by `(post_id,term_id)`, while a non-anchor
prefix intersects rare exact candidates. Neither shape scans unrelated
per-document postings. One optional statement hydrates only the returned page.

Exact broad `OR` and single broad prefixes still have to examine their matching
posting rows to rank the exact top page. They remain a fixed plan/rank/hydrate
shape with page-sized PHP memory, but database work is proportional to matching
postings; sites beyond that small/medium-site tradeoff should use a dedicated
search service.

Pages return `has_more` and signed forward/reverse cursors. They do not carry
a total. Numbered deep offsets and synchronous exact totals would require
repeated or exhaustive work. Valid WordPress query shapes with
unsupported membership, projection, ordering, page-size, or numbered-pagination
constraints remain on core search. Once FTS owns an otherwise-supported search,
an unavailable index or malformed/oversized adapter input fails closed instead
of silently running an unindexed core `LIKE`/`OFFSET` query.

Those traces also show a Performance budget row for completed search timing
data. By default, total search time is compared with a `100ms` budget and the
`storage/search` phase is compared with a `50ms` budget. Define
`WP_FTS_SEARCH_TOTAL_BUDGET_MS` or `WP_FTS_SEARCH_STORAGE_BUDGET_MS`, or filter
`wp_fts_search_performance_budget`, to tune the thresholds. Zero or negative
values disable the corresponding budget.

SQL query summaries appear only when WordPress or a compatible debug/test
database object has already populated `$wpdb->queries`, typically by defining
`SAVEQUERIES` in the environment. The plugin does not enable `SAVEQUERIES`
automatically and does not create persistent SQL logs.

## Language And Morphology

Language routing is explicit-first. Use `wp fts reindex --lang=...`,
`wp fts search --lang=...`, or the sandbox language selector when you know the
language. In wp-admin, the `FTS Language` post field can pin indexing for a
post. It is loaded with the worker's set-oriented metadata snapshot. HTML
`lang`/`xml:lang` scopes and custom analyzer resolvers are also honored, with
HTML scopes able to route individual visible segments. The bounded worker reads
each active Polylang/WPML assignment table once for the claimed batch and does
not install or invoke provider-backed document/query resolvers. Explicit custom
resolver callbacks remain a framework-neutral extension surface; their I/O is
caller-owned and outside the plugin's fixed SQL contract.

Automatic detection is conservative, deterministic gap filling, not statistical
or ML language detection. It only runs for untagged visible text groups after
stronger language signals are absent. The detector looks at script ranges,
distinctive Latin letters, and compact lexical evidence with thresholds; one
weak marker is not enough. Inline HTML is reduced to visible words before
morphology, so text split across nested inline tags is analyzed as the reader
sees it.

If no language can be detected, analysis falls back instead of failing.
Documents fall back through the primary document language, detector evidence,
site locale, and the analyzer default. Queries fall back through explicit
language, detector evidence, site locale, and the analyzer default. In Playground,
that usually means `en-US`/`en` unless you choose another language.

Terms are stored in language partitions such as `pl:chrzastka` or `en:search`,
and query terms must resolve to the same partition to match. Automatic detection
does not search every language because broad cross-language searches would add
noisy matches and make ranking less useful. Use an explicit language when the
same surface can belong to more than one language partition.

The baseline selectable and detectable routing set covers English (`en`),
Mandarin/Chinese (`zh`), Hindi (`hi`), Spanish (`es`), Arabic (`ar`), French
(`fr`), Bengali (`bn`), Portuguese (`pt`), Indonesian (`id`), and Urdu (`ur`).
The next language set adds Russian (`ru`), German (`de`), Japanese (`ja`),
Korean (`ko`), Telugu (`te`), Turkish (`tr`), Italian (`it`), Persian (`fa`),
Ukrainian (`uk`), and Dutch (`nl`). Polish (`pl`) remains the reference
morphology lane.

| Language or partition | Current analyzer tier | Boundary |
| --- | --- | --- |
| Polish (`pl`) | The WordPress runtime loads the bundled compressed `pl-polimorf-20180722-full` pack when gzip support is available. `lemma_packs_by_lang['pl']` replaces or disables that default; without a valid pack, the conservative Polish stemmer runs. | The raw CLARIN-PL source archive and extracted TSV are not bundled. The small PoliMorf contract pack lives under `tests/fixtures/` and is never a runtime fallback. |
| English (`en`), Hindi (`hi`), Spanish (`es`), Arabic (`ar`), French (`fr`), Bengali (`bn`), Portuguese (`pt`), Indonesian (`id`), Russian (`ru`), German (`de`), Telugu (`te`), Turkish (`tr`), Italian (`it`), Persian (`fa`), Ukrainian (`uk`), Dutch (`nl`) | Bundled source-backed UniMorph analyzer packs are available as opt-in gzip-sharded lemma packs from Settings > Full-Text Search > Analyzer packs, or through `lemma_packs_by_lang`. | Packs activate only through plugin configuration and are not synonym, phrase, or cross-language expansion. Built-in Snowball/baseline/no-op behavior remains the fallback when no pack is configured. |
| Catalan (`ca`), Dutch Porter (`nl`) | Wamania-backed Snowball support from the required Composer runtime. | Dutch has a source-backed UniMorph pack when configured; the Wamania path is the no-pack fallback. Other Wamania languages are treated as no-ops unless they become verified. |
| Chinese (`zh`) | Deterministic CJK fallback plus optional Jieba dictionary segmentation from the curated pinned runtime, or the initialized source checkout during development, via `segmenter_packs_by_lang`. | Release ZIPs carry the verified runtime manifest, MIT dictionary, license, and attested lookup. Custom dictionaries are not supported. Fallback n-grams remain enabled. |
| Japanese (`ja`), Korean (`ko`) | Deterministic CJK/Hangul fallback tokenization with selectable/detectable language partitions. | No Japanese or Korean runtime lemma pack is committed because the current PHP pipeline has no source-backed word segmenter for those languages. Pinned UniMorph source submodules are retained for future external-pack work. |
| Urdu (`ur`) | Arabic-script mark/tatweel normalization plus deterministic suffix baseline for common plural-oblique forms. | UniMorph Urdu imports technically, but the upstream `unimorph/urd` repository has no license evidence, so no generated Urdu pack is committed. |
| Generic packs | `lemma_packs_by_lang` accepts local manifest-backed packs with matching `language` values. | An absent entry or native `false` selects the built-in analyzer. A configured missing, invalid, or language-mismatched pack stops analyzer construction. |

Morphology support must come from verified algorithms, analyzers, or
manifest-backed lemmatizer packs. The plugin does not use hard-coded word
families for product behavior.

Importer availability is not the same as pack-backed language support. To audit
top-language readiness, run
`php tools/audit-top-language-lemma-packs.php --pack-root=/path --json --require-pack-backed`.
Languages reported as missing, invalid, or license-blocked are not ready to
claim pack-backed quality. Chinese, Japanese, and Korean are tokenizer lanes
rather than missing UniMorph lemma packs. The source tree keeps their optional
or future source data as gitlinks; the WordPress release builder additionally
stages the verified Jieba manifest, dictionary, license, and lookup under its
runtime path.

The analyzer also provides CJK fallback tokenization with one-character runs
kept as-is and longer runs emitted as character unigrams plus deterministic
overlapping n-grams up to 4 characters. Release ZIPs contain the curated Jieba
runtime. For a source checkout, initialize the optional Jieba source with:

```sh
components/full-text-search/tools/initialize-jieba-source.sh
```

The runtime uses an attested first-codepoint lookup and verifies each dictionary
range when first read instead of hashing the 5-MiB source per request. Missing,
uninitialized, or mismatched data falls back to CJK n-grams. Production custom
Jieba dictionaries are not currently supported. The plugin does not currently
ship Thai dictionary segmentation.

## Snippets And Highlighting

Snippets come from bounded plain text extracted from saved `post_content` during
indexing, not from live post rendering at search time. Source markup is not
stored in the result-document row.

When highlighting is enabled, the highlighter compares snippet tokens through
the same analyzer path used for the query. The result contains escaped visible
text plus internally generated `<mark>` elements only, so source tags and
attributes cannot become executable output. It can still highlight the matched
document surface when the query and document forms differ through stemming or
lemmatizer equivalence.

## Common Commands

```sh
# Index admin-searchable post statuses using site or batch-preloaded post language hints.
wp fts reindex

# Index public posts and pages into an explicit language partition.
wp fts reindex --post_type=post,page --post_status=publish --lang=pl-PL

# Queue a scope that will discover at most 100 matching posts.
wp fts reindex --limit=100 --format=json

# Run one bounded worker pass without turning reindex into a synchronous drain.
wp fts process-batch --batch_size=25 --time_budget=20 --format=json

# Inspect stored lifecycle status without indexing, physical schema probes, or
# corpus counts. Use `wp fts diagnose` when bounded physical verification and
# query explain data are needed; it still does not count the corpus.
wp fts status
wp fts status --format=json

# Restore a missing future queue processor event without indexing inline.
wp fts schedule-queue
wp fts schedule-queue --format=json

# If status reports last_batch_failures, fix the affected post or environment
# issue and rerun a bounded batch or scoped reindex.

# Atomically replace only the derived FTS generation and runtime indexing state.
# Requires confirmation, does not scan or report removed-row counts, and
# preserves WordPress posts, plugin settings, analyzer options, and schema
# version. It automatically queues one complete background reconciliation; do
# not add a manual filtered reindex. process-batch can advance the queued scope.
wp fts reset-index --yes
wp fts reset-index --yes --format=json

# Repair schema without indexing content.
wp fts repair

# Advance one bounded queue/backfill batch under operator control.
wp fts process-batch --batch_size=100 --time_budget=20

# Require every analyzed query term to match.
wp fts search "fast durable search" --mode=AND --lang=en --limit=10

# Filter by stored WordPress metadata and include snippets.
wp fts search "fast durable search" --post_type=post,page --post_status=publish --snippet

# Give recent posts a bounded query-time lift using indexed post_date_gmt metadata.
wp fts search "fast durable search" --recency_boost_strength=0.3 --recency_boost_half_life_days=30

# Reconcile one missing/ineligible canonical post. Eligible canonical posts are
# rejected because deleting only their derived row would be self-reversing.
wp fts delete 123

# Remove one indexed page of at most 1,000 zero-frequency dictionary rows.
wp fts optimize
```

## Documentation

- [Configuration](docs/configuration.md) covers languages, analyzers,
  stemmers, content extraction, and relational ranking options.
- [Operations](docs/operations.md) covers schema creation, reindexing,
  optimization, backups, restores, and sizing notes.
- [Limitations](docs/limitations.md) lists current behavior that production
  operators need to account for.
- [Testing](docs/testing.md) documents the PHP, analyzer, relevance, and
  constrained real-database integration harnesses.
- [Release packaging](docs/release-packaging.md) describes what should ship in
  a plugin archive and how the component dependency is handled.
- [Snowball compliance](docs/snowball-compliance.md) explains the dedicated
  Snowball fixture harness.
- [Analyzer source locks](docs/analyzer-source-locks.md) define the manifest
  schema required before analyzer or lemmatizer data imports. The generic
  normalized lemma TSV, CoNLL-U, and UniMorph-style lemma importers are covered in
  [Configuration](docs/configuration.md#importing-normalized-lemma-tsv-packs)
  [Configuration](docs/configuration.md#importing-conllu-lemma-packs), and
  [Configuration](docs/configuration.md#importing-unimorph-style-lemma-packs).
- [Tokenizer source locks](docs/tokenizer-source-locks.md) documents the
  pre-coding gate for any future Thai TCC/dictionary tokenizer and the narrow
  pinned-source boundary for optional Chinese Jieba segmentation. The current
  plugin does not ship real Thai word segmentation.
- [Polish PoliMorf packs](docs/polish-morfologik-fixture-pack.md) separates
  the bundled full runtime from the test-only contract pack.

## Current Caveats

Language FTS should be evaluated in the target environment before it is used for
visitor-facing search. Validate schema creation, write throughput, batch sizes,
language choices, metadata filters, backups, restore behavior, cron behavior,
database load, and interactions with the site's theme and plugins before
enabling it on live traffic.

Current caveats:

- front-end search replacement is enabled by default for ordinary supported
  search archives. A non-null result from an earlier `posts_pre_query` provider
  is always preserved. The default **Use Language FTS when providers abstain**
  mode accepts only a null handoff from an earlier provider; the stricter
  **Keep provider-integrated searches on WordPress** mode leaves the whole
  query on core whenever a third-party provider callback is registered. SQL
  shaping, request-stage, later-provider, and post-result membership callbacks
  already registered before ranking also keep valid searches on core with zero
  FTS statements. If one first appears during the bounded relational page, the
  ranked page is discarded and the already-owned query fails closed with later
  result filters suppressed; it never falls through to core LIKE after FTS SQL.
  Fresh settings index post, page, and attachment so stock unscoped searches
  have the same built-in type surface as core. A deliberately saved scope that
  omits any currently searchable type keeps unscoped searches on core. Feed, embed,
  preview, singular, and other non-search-archive routes remain on WordPress.
  The Health and Settings tabs show a read-only advisory
  when common providers such as Jetpack Search/Jetpack, SearchWP, Relevanssi, or
  ElasticPress are detected from safe activation/option/class/function signals;
  that advisory does not call provider APIs and is not certification that those
  products have been tested end to end. Request diagnostics can also show a
  bounded `posts_pre_query` hook pipeline with callback labels and priorities,
  without executing callbacks or including provider result payloads. The stock
  WordPress comment-state `the_posts` callback is recognized as membership
  neutral; foreign `the_posts` callbacks are not;
- wp-admin Posts list search replacement is enabled for safe main-list searches
  over indexed supported admin post statuses and uses the same provider
  compatibility setting. Its pre-LIMIT gate follows each registered post
  type's capability map: other authors' draft/pending rows require
  `edit_others_posts`, future rows also require `edit_published_posts`, and
  private rows require `read_private_posts`; an unrepresentable scope and valid
  numbered admin pagination stay on WordPress. A supported FTS-owned shape still
  fails closed if the relational index becomes unavailable. The
  `wp_fts_replace_frontend_search` and
  `wp_fts_replace_admin_post_search` filters can still disable a whole
  replacement surface;
- Settings > Full-Text Search covers operational search/index defaults, but
  analyzer pack paths and custom field indexing still use options and filters;
- custom field indexing must be configured;
- no Thai dictionary segmentation;
- Chinese Jieba dictionary segmentation is optional; releases carry the curated
  runtime, while source checkouts require the pinned submodule to be initialized;
- no phrase search unless an extension supplies that backend.
