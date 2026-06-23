# Language FTS

[![Try in Playground](https://github.com/WordPress/action-wp-playground-pr-preview/raw/main/assets/playground-preview-button.svg)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/adamziel/wp-extensions/main/indexer/playground/blueprint.json)

Language FTS is a WordPress plugin that builds a local full-text search index
for WordPress content. It stores derived index data in WordPress database
tables, keeps that data current through bounded WordPress lifecycle and queue
workflows, and exposes practical admin, WP-CLI, REST/search, and diagnostic
surfaces for understanding how search behaves on a site.

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
engine; this plugin owns WordPress hooks, post extraction, MySQL storage, admin
UI, WP-CLI, REST/search integration, and Playground packaging.

The Playground preview installs this `indexer/` plugin directly from the GitHub
repository with a `git:directory` Blueprint resource and opens the admin-only
Settings > Full-Text Search Sandbox tab. The sandbox searches content already
present in the full-text index and never creates demo posts or hidden sample
content. It shows indexed posts with pagination, lets you run language-aware
searches, and indexes new or updated published posts when they are saved.
Playground is useful for trying the workflow quickly; production suitability
still depends on the site, host, database, cron, cache, content, traffic, and
plugin/theme mix.

The plugin does not use MySQL `FULLTEXT`, replaces normal front-end main-query
search and eligible wp-admin Posts list searches with ranked FTS results by
default, and treats the index as rebuildable data derived from WordPress
content.

Settings > Full-Text Search provides Health, Settings, Sandbox, Indexed content,
and Analyzer packs tabs. It manages the indexed post types, automatic indexing,
search replacement surfaces, search-provider compatibility, highlighting,
snippets, prefix matching, result limits, field ranking weights, an optional
recency ranking boost, language fallback defaults, schema status, and a Health
tab schema repair action. The Analyzer packs tab can enable or disable bundled
runtime lemma packs when PHP gzip support is available; custom analyzer-pack
paths and custom field selection still use the documented options and filters.

## Quickstart

Install the `indexer` directory as the plugin root. Do not install the whole
monorepo under `wp-content/plugins`, because WordPress will not discover
`indexer/indexer.php` from a nested checkout.

```sh
rsync -a --delete /path/to/wp-extensions/indexer/ /path/to/wordpress/wp-content/plugins/indexer/
cd /path/to/wordpress/wp-content/plugins/indexer
composer install --no-dev --optimize-autoloader
wp plugin activate indexer
```

Activation creates or repairs the `fts_*` tables and schedules the bounded
runtime queue processor. Run a first reindex to backfill existing posts that
the wp-admin Posts list replacement can search:

```sh
wp fts reindex --post_type=post --batch_size=200
```

The default post status scope for `wp fts reindex` is
`publish,draft,pending,future,private`, matching the admin Posts list search
surface for `post`. Normal front-end search replacement still returns published
content only; use `--post_status=publish` for a public-only backfill.

Run a search with the language you expect:

```sh
wp fts search "example query" --lang=en --limit=5
```

The output includes WordPress post IDs, BM25 scores, totals, stored metadata,
and optional snippets. `score` is relative to the current query and language
partition; it is not a percentage and should not be compared across unrelated
queries.

## Ranking Field Weights

Settings > Full-Text Search > Settings includes ranking weights for the
WordPress post fields extracted into the index: title, main content, excerpt,
taxonomy terms, selected custom fields, and rendered-only content. Higher
numbers make matches in that field count more strongly during ranking.

The defaults match the extractor defaults: title `5.0`, content `1.0`, excerpt
`2.0`, taxonomy terms `1.5`, selected custom fields `1.0`, and rendered-only
content `1.0`. These are index-time weights stored with indexed content, so
changed weights fully affect existing content only after it is reindexed.
Saving changed weights marks stale reindex debt in Health/status; it does not
index content during the settings save.

The same Ranking weights section also includes an optional recent post boost.
It is off by default (`0`). When enabled, query-time ranking gives newer posts a
small bounded lift using indexed `post_date_gmt` metadata. Changing the strength
or half-life does not require reindexing when the date metadata is already in
the index. Missing, empty, or invalid dates are ignored safely, and search
explain diagnostics report whether the boost was enabled and how many candidate
documents received it.

## Word Beginnings

Word beginnings can be enabled or disabled from Settings > Full-Text Search.
When enabled, exact analyzer and lemma matches still rank before prefix-only
alternatives. The saved `prefix_min_length` default is `4`; lowering it broadens
matches for shorter searched words, which can be slower or noisier. The saved
`prefix_max_terms` default is `64`; lowering it caps broad-prefix cost more
aggressively, while raising it can include more alternatives.

The saved thresholds apply to front-end replacement, wp-admin Posts search
replacement, the Sandbox, and `WP_FTS_Plugin::search()`. `wp fts search`
accepts `--prefix_matching`, `--prefix_min_length` / `--prefix-min-length`, and
`--prefix_max_terms` / `--prefix-max-terms` for explicit CLI searches. Direct
searcher callers can still use the existing `WP_FTS_PREFIX_MIN_LENGTH` and
`WP_FTS_PREFIX_MAX_TERMS` constants for code-level overrides.

## Architecture

- `wp-php-toolkit/full-text-search` provides the analyzer, term generation,
  storage contracts, in-memory/file storage, `WP_FTS_Indexer`, and
  `WP_FTS_Searcher`.
- WordPress activation, post-save/status/delete hooks, cron, REST, and WP-CLI
  live in the plugin adapter and wire WordPress posts into the component.
- `WP_FTS_PostContentExtractor` extracts title, content, excerpt, rendered
  block deltas, taxonomy terms, and configured custom fields into weighted
  fields plus bounded result metadata.
- `WP_FTS_Analyzer` strips non-visible HTML, normalizes and tokenizes text,
  routes language gaps, and stems or lemmatizes through the language pipeline.
- Terms are stored under language namespaces, so the baseline top-10 routed
  languages plus Polish, German, Russian, and other explicit partitions do not
  share collection statistics by accident.
- `WP_FTS_Searcher` scores matches with BM25 and can filter by stored WordPress
  metadata such as post type, status, and date.
- MySQL storage is the normal WordPress backend, including WordPress Playground
  when the SQLite integration presents a `$wpdb`-compatible database. File
  storage remains in the component as a small local and test backend for
  non-WordPress contexts.

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
before completion.

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

The Health tab shows whether the stored schema version is current, missing, or
stale. Its repair button runs the same table/schema repair path as
`wp fts repair`; it touches schema and table definitions only and does not index
content or create sample posts.

## Feature Summary

| Area | Current support |
| --- | --- |
| Indexing | Builds derived `fts_*` tables from WordPress posts, including title, content, excerpt, rendered block deltas, taxonomy terms, selected custom fields, boosts, and bounded result metadata. |
| Lifecycle updates | Activation repairs schema, WP-Cron drains bounded runtime work, save/status hooks queue eligible updates, status/delete hooks tombstone posts that leave searchable scopes, and `wp fts reindex` can rebuild a scoped corpus. |
| Language routing | Terms are stored in language namespaces. Explicit `--lang`, the wp-admin `FTS Language` field, Polylang/WPML metadata, and HTML `lang`/`xml:lang` scopes route content before conservative detector fallback. |
| Search | BM25 scoring supports `OR`/`AND`, `limit`/`offset`, language-aware query analysis, and stored WordPress metadata filters. |
| Snippets | Search can return snippets from bounded extracted metadata, with HTML-aware highlighting based on analyzed query/document keys rather than literal text only. |
| Surfaces | WP-CLI is the main operational surface. The plugin also registers a REST search helper, PHP search helper, front-end main-query replacement, eligible wp-admin Posts list replacement, and admin-only Settings > Full-Text Search tabs used by the Playground preview. |
| Diagnostics | Request-level FTS traces are available to authorized/debug contexts through Debug Bar when installed, or on the Health tab fallback. They include bounded search explain summaries with storage, query surfaces and analyzed terms, fast-mode, scoring, recency boost status, per-result and field-specific match details, performance-budget status, a bounded `posts_pre_query` hook pipeline around Language FTS, and redacted SQL query summaries when the environment already collects `$wpdb->queries`; they are request-local diagnostics rather than persistent logs. |

## Search Accuracy And Automatic Fast Mode

Exact search is the correctness-first baseline. For small or targeted searches,
the searcher considers every matching candidate document, which preserves the
best recall, ranking, and total-count exactness.

Broad searches automatically switch to approximate fast top-K mode when the
analyzed query's estimated matching candidate document count exceeds the
configured threshold. That estimate is based on the query terms after analysis
and supported metadata filters; it is not based on the total number of indexed
posts or pages. Automatic fast mode is enabled by default, uses a threshold of
`2000` candidates, and scores up to `1000` candidates unless configured
otherwise.

Fast mode can improve latency for broad queries, but it is approximate: recall,
ranking, and total counts may differ from exact scoring. Tune the policy in
`wp-config.php` or an early plugin/bootstrap file before the plugin loads:

```php
define('WP_FTS_FAST_MODE_THRESHOLD', 2000);
define('WP_FTS_FAST_MODE_CANDIDATE_CAP', 1000);
define('WP_FTS_FAST_MODE_ENABLED', true);
```

Lower threshold or cap values switch sooner or score fewer candidates, which is
faster and less complete. Higher values keep more broad-query candidates, which
is more complete and slower. Set `WP_FTS_FAST_MODE_ENABLED` to `false` when a
site needs exact broad-query recall and exact totals more than broad-query
latency.

Explicit search options still win over the automatic policy. Programmatic
callers can force exact scoring with `exact_top_k`, `exact`, or an explicit
false `fast_top_k`; explicit `fast_top_k` or `approximate_top_k` opts into
approximate top-K mode for that search.

When diagnostics are active, the Debug Bar panel or Health-tab fallback shows
which path was used for the current request: exact, explicit approximate, auto
threshold, forced exact, disabled by constant, or no threshold crossing. The
same trace reports a bounded human-readable fast-mode reason, the candidate
estimate, threshold, candidate cap, and whether the result total is exact or
approximate.

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
post. HTML `lang`/`xml:lang` scopes, Polylang/WPML metadata, and custom analyzer
resolvers are also honored, with HTML scopes able to route individual visible
segments.

Automatic detection is conservative, deterministic gap filling, not statistical
or ML language detection. It only runs for untagged visible text groups after
stronger language signals are absent. The detector looks at script ranges,
distinctive Latin letters, and compact lexical evidence with thresholds; one
weak marker is not enough. Inline HTML is reduced to visible words before
morphology, so text split across nested inline tags is analyzed as the reader
sees it.

If no language can be detected, analysis falls back instead of failing.
Documents fall back through the primary document language, post metadata, site
locale, and the analyzer default. Queries fall back through the selected or
current query language, site locale, and the analyzer default. In Playground,
that usually means `en-US`/`en` unless you choose another language.

Terms are stored in language partitions such as `pl:chrzastka` or `en:search`,
and query terms must resolve to the same partition to match. Automatic detection
does not search every language because broad cross-language searches would add
noisy matches and make ranking less useful. In the sandbox, use the `Indexed
terms` column to inspect the actual stored postings when the visible preview
text and indexed analyzer output differ.

The baseline selectable and detectable routing set covers English (`en`),
Mandarin/Chinese (`zh`), Hindi (`hi`), Spanish (`es`), Arabic (`ar`), French
(`fr`), Bengali (`bn`), Portuguese (`pt`), Indonesian (`id`), and Urdu (`ur`).
The next language set adds Russian (`ru`), German (`de`), Japanese (`ja`),
Korean (`ko`), Telugu (`te`), Turkish (`tr`), Italian (`it`), Persian (`fa`),
Ukrainian (`uk`), and Dutch (`nl`). Polish (`pl`) remains the reference
morphology lane.

| Language or partition | Current analyzer tier | Boundary |
| --- | --- | --- |
| Polish (`pl`) | The WordPress runtime keeps the bundled Polish lemmatizer behavior by default: it uses the compressed full Polish runtime pack when gzip support is available and falls back to the bundled fixture pack otherwise. `polish_lemma_pack` and `polish_lemmatizer_pack` remain supported aliases to replace or disable that default. | The raw CLARIN-PL source archive, extracted TSV, and separately generated external PoliMorf pack are not bundled in release archives. |
| English (`en`), Hindi (`hi`), Spanish (`es`), Arabic (`ar`), French (`fr`), Bengali (`bn`), Portuguese (`pt`), Indonesian (`id`), Russian (`ru`), German (`de`), Telugu (`te`), Turkish (`tr`), Italian (`it`), Persian (`fa`), Ukrainian (`uk`), Dutch (`nl`) | Bundled source-backed UniMorph analyzer packs are available as opt-in gzip-sharded lemma packs from Settings > Full-Text Search > Analyzer packs, or through `lemma_packs_by_lang` / `lemmatizer_packs_by_lang`. | Packs are CC BY-SA-family or upstream-declared data, default-disabled for production runtime, and not synonym, phrase, or cross-language expansion. Built-in Snowball/baseline/no-op behavior remains the fallback when no pack is configured. |
| Catalan (`ca`), legacy Dutch Porter fallback (`nl`) | Optional Wamania-backed Snowball support when Composer dependencies are installed and the compliance harness accepts them. | Dutch now has a source-backed UniMorph pack when configured; the Wamania path is only the no-pack fallback. Other Wamania languages are treated as no-ops unless they become verified. |
| Chinese (`zh`) | Deterministic CJK fallback plus optional Jieba dictionary segmentation from the pinned `indexer/resources/sources/jieba` submodule via `segmenter_packs_by_lang`. | Jieba is MIT source data, default-disabled outside the sandbox, and is segmentation only. Fallback n-grams remain enabled for unknown/subword recall. |
| Japanese (`ja`), Korean (`ko`) | Deterministic CJK/Hangul fallback tokenization with selectable/detectable language partitions. | No Japanese or Korean runtime lemma pack is committed because the current PHP pipeline has no source-backed word segmenter for those languages. Pinned UniMorph source submodules are retained for future external-pack work. |
| Urdu (`ur`) | Arabic-script mark/tatweel normalization plus deterministic suffix baseline for common plural-oblique forms. | UniMorph Urdu imports technically, but the upstream `unimorph/urd` repository has no license evidence, so no generated Urdu pack is committed. |
| Generic packs | `lemma_packs_by_lang` / `lemmatizer_packs_by_lang` accept local manifest-backed packs with matching `language` values. | Missing, invalid, disabled, or language-mismatched packs fall back safely. |

Morphology support must come from verified algorithms, analyzers, or
manifest-backed lemmatizer packs. The plugin does not use hard-coded word
families for product behavior.

Importer availability is not the same as pack-backed language support. To audit
top-language readiness, run
`php tools/audit-top-language-lemma-packs.php --pack-root=/path --json --require-pack-backed`.
Languages reported as missing, fixture-only, or license-blocked are not ready to
claim pack-backed quality. Chinese, Japanese, and Korean are tokenizer lanes
rather than missing UniMorph lemma packs; their optional or future source data is
kept as git submodules, not copied dictionary rows in this repository.

The analyzer also provides CJK fallback tokenization with one-character runs
kept as-is and longer runs emitted as character unigrams plus deterministic
overlapping n-grams up to 4 characters. Initialize optional Jieba source data
with:

```sh
git submodule update --init --recursive indexer/resources/sources/jieba
```

The runtime verifies `jieba/dict.txt` against the pinned SHA-256 before using
it. Missing, uninitialized, or hash-mismatched source data falls back to CJK
n-grams. The plugin does not currently ship Thai dictionary segmentation.

## Snippets And Highlighting

Snippets come from bounded metadata extracted during indexing, not from live
post rendering at search time. When indexed fields provide HTML, a bounded HTML
source is stored alongside plain text so inline markup can be preserved in
highlighted snippets.

When highlighting is enabled, the highlighter compares snippet tokens through
the same analyzer path used for the query. The HTML path scans text nodes and
source offsets rather than applying regex replacements, so a result can
highlight the matched document surface form while preserving nested inline tags,
including when the query form and document form differ through stemming or
lemmatizer equivalence.

## Common Commands

```sh
# Index admin-searchable post statuses for posts using site, post, or multilingual-plugin language hints.
wp fts reindex

# Index public posts and pages into an explicit language partition.
wp fts reindex --post_type=post,page --post_status=publish --lang=pl-PL

# Limit a smoke or catch-up run.
wp fts reindex --limit=100 --batch_size=25

# Inspect lifecycle status without indexing or mutating state.
wp fts status
wp fts status --format=json

# Restore a missing future queue processor event without indexing inline.
wp fts schedule-queue
wp fts schedule-queue --format=json

# If status reports last_batch_failures, fix the affected post or environment
# issue and rerun a bounded batch or scoped reindex.

# Clear only derived FTS index data and runtime indexing state. Requires
# confirmation and preserves WordPress posts, plugin settings, analyzer options,
# and schema version.
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
wp fts search "fast durable search" --recency_boost=0.3 --recency_boost_half_life_days=30

# Tombstone one document and compact tombstones later.
wp fts delete 123
wp fts optimize
```

## Documentation

- [Configuration](docs/configuration.md) covers languages, analyzers,
  stemmers, content extraction, and BM25 options.
- [Operations](docs/operations.md) covers schema creation, reindexing,
  optimization, backups, restores, and sizing notes.
- [Limitations](docs/limitations.md) lists current behavior that production
  operators need to account for.
- [Testing](docs/testing.md) documents the PHP, Snowball, BM25, and conditional
  integration test harnesses.
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
- [Polish fixture pack](docs/polish-morfologik-fixture-pack.md) explains the
  opt-in Morfologik/PoliMorf-compatible lemmatizer contract slice.
- [Polish verified stemmer](docs/polish-verified-stemmer.md) explains the
  opt-in fixture-backed Polish stemmer slice and how it differs from
  dictionary lemmatization.

## Current Caveats

Language FTS should be evaluated in the target environment before it is used for
visitor-facing search. Validate schema creation, write throughput, batch sizes,
language choices, metadata filters, backups, restore behavior, cron behavior,
database load, and interactions with the site's theme and plugins before
enabling it on live traffic.

Current caveats:

- front-end search replacement is enabled by default and runs late in the
  WordPress search hooks so configured front-end searches are owned by FTS. The
  provider compatibility setting defaults to Prefer Language FTS; switch it to
  keep another search provider's results when Jetpack Search, SearchWP,
  Relevanssi, a theme filter, or custom search code appears to win or lose and
  should answer first. The Health and Settings tabs show a read-only advisory
  when common providers such as Jetpack Search/Jetpack, SearchWP, Relevanssi, or
  ElasticPress are detected from safe activation/option/class/function signals;
  that advisory does not call provider APIs and is not certification that those
  products have been tested end to end. Request diagnostics can also show a
  bounded `posts_pre_query` hook pipeline with callback labels and priorities,
  without executing callbacks or including provider result payloads;
- wp-admin Posts list search replacement is enabled for safe main-list searches
  over indexed supported admin post statuses and uses the same provider
  compatibility setting. The `wp_fts_replace_frontend_search` and
  `wp_fts_replace_admin_post_search` filters can still disable a whole
  replacement surface;
- Settings > Full-Text Search covers operational search/index defaults, but
  analyzer pack paths and custom field indexing still use options and filters;
- custom field indexing must be configured;
- shortcode rendering is opt-in;
- no Thai dictionary segmentation;
- Chinese Jieba dictionary segmentation is optional and requires the pinned
  submodule source to be initialized and hash-valid;
- no phrase search unless an extension supplies that backend.
