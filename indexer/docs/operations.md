# Operations

Pure PHP FTS Indexer stores derived search data in custom MySQL tables. Treat
the index as rebuildable from WordPress content, but back it up when fast
restore matters.

## Schema Creation And Upgrade

Activation creates or repairs the index tables, stores the schema contract
version, and schedules the bounded runtime queue processor. Runtime storage
access also checks the stored schema version and repairs stale or missing schema
state before indexing or tombstoning posts.

On multisite, activation, the Health-tab repair action, and `wp fts repair`
operate on the current site's table prefix. When WordPress initializes a new
site, the plugin switches into that site, creates or repairs the seven FTS tables,
schedules bounded queue work, and does not index content or set the activation
redirect flag.

To explicitly repair the schema without indexing content, use Settings >
Full-Text Search > Health > Repair schema tables or run:

```sh
wp fts repair
```

To inspect schema, queue, lock, last-batch, and remaining-work state without
mutating the index, run:

```sh
wp fts status
wp fts status --format=json
```

Status includes `index_profile_hash`, `accepted_index_profile_hash`,
`stale_debt_active`, stale-debt reason labels, debt timestamps, the active
stale-debt cursor, processed count, remaining indexed-row count, and latest
stale batch count. Stale debt means index-time configuration changed after the
last accepted profile, so existing rows may need to be rewritten even when
"Remaining to index" is `0`.

Status also includes `failure_recovery`, a bounded read-only history of recent
item-level indexing failures. The records include sanitized post IDs, bounded
titles/labels, first/latest failure timestamps, attempt counts, source/mode,
redacted error summaries, and retry state. The history is capped by item count
and JSON byte size; it does not store post bodies, raw SQL, stack traces,
secrets, or unbounded option payloads.

Status also includes `queue_processor_schedule`, a read-only view of the
`wp_fts_process_index_queue` WP-Cron event. `scheduled` means WordPress has a
queue processor event waiting and reports the next UTC run time and delay.
`missing` means bounded status inputs show pending queue, backfill, or stale
reindex work but no queue processor event is scheduled. Use the Health tab's
queue processor control or `wp fts schedule-queue` to restore the future
background event, then check for disabled or stalled WP-Cron. Schedule recovery
only schedules a later background run; it does not index content immediately.
`wp fts process-batch --batch_size=100 --time_budget=20` remains the manual
one-pass fallback while cron is investigated. `not_needed` means no pending
indexing work was detected, and `unavailable` means the current non-WordPress
context cannot inspect cron helpers.

Status also includes `cron_runner`, a read-only environment diagnostic for
traffic-triggered WP-Cron. `traffic_triggered` means normal WordPress traffic can
start WP-Cron in this process. `external_required` means `DISABLE_WP_CRON` is
enabled; when pending work exists, a scheduled queue event alone is not enough
and the site needs a host/system cron trigger for `wp-cron.php` or bounded manual
batches such as `wp fts process-batch --batch_size=100 --time_budget=20` while
cron is fixed. `unknown` means the current context cannot confirm the runner
mode. The Health tab renders the same fields, including `DISABLE_WP_CRON`,
`ALTERNATE_WP_CRON`, pending-work context, and concise advice.

Status also includes `search_provider_compatibility`, a read-only summary of the
effective provider compatibility mode, public-site and wp-admin Posts
replacement state, bounded known-provider family names, and the current
recommendation/advisory text. `wp fts status --format=json` exposes the nested
block for automation; table output also includes concise flattened rows such as
`search_provider_compatibility_mode` and
`search_provider_compatibility_known_provider_names`. This status path reads
safe activation, option, class, and function signals only. It does not call
third-party provider search APIs, invoke provider callbacks, run searches,
mutate options, drain the queue, write index tables, or expose raw plugin
basenames/provider payloads.

Status also includes `language_pack_status`, a read-only runtime analyzer-pack
summary for automation and support. JSON output preserves the nested object with
the current site language, runtime support label/reason, matched base runtime
language when a locale such as `en-US` is covered by `en`, fallback-language
state, gzip/runtime-pack availability, bounded active runtime pack summaries,
bounded fallback/unsupported/license-blocked language summaries, and an operator
recommendation. Table output keeps the nested row and adds concise flattened
rows such as `language_pack_site_language`,
`language_pack_runtime_support`, `language_pack_active_runtime_languages`,
`language_pack_gzip_status`, and `language_pack_recommendation`. This status is
advisory only: it does not install analyzer packs, change analyzer options,
create posts or demo content, drain queues, run indexing, or reindex existing
content.

To create or repair the schema and index content, run:

```sh
wp fts reindex --post_type=post
```

The MySQL backend creates seven tables under the active WordPress table prefix:

- `fts_terms`: binary term keys and document frequency.
- `fts_postings`: row postings keyed by `(term, doc_id)` with term frequency.
- `fts_docs`: one row per document with primary language, aggregate length,
  content hash, and tombstone state.
- `fts_doc_lengths`: per-document, per-language lengths used for BM25.
- `fts_docmeta`: bounded WordPress result metadata for filters, snippets, and
  CLI/REST enrichment.
- `fts_meta`: per-language document counts and length sums.
- `fts_queue`: coalesced post generations, retry timing, and leased worker
  ownership for pending indexing work.

When WordPress `dbDelta()` is available, schema creation uses it so existing
tables can evolve in place. Outside that path, raw `CREATE TABLE` statements
are executed. Database write failures are surfaced with the failed operation
name so activation, repair, and runtime indexing do not silently continue after
a schema or storage error.

During multisite site deletion, the plugin contributes the target site's seven
`fts_*` table names to WordPress table discovery so core can clean them up with
the deleted site. Plugin uninstall remains intentionally conservative: it clears
plugin operational options and pending queue state for each site on multisite,
but does not drop index tables or delete indexed data. Uninstall does not create
or delete posts, demo content, terms, users, attachments, uploads, analyzer
packs, generated packs, or release artifacts.

Disposable lifecycle evidence is available through
`tools/run-disposable-lifecycle-smoke.sh` for direct-install/operator reviews.
That smoke creates an isolated Docker WordPress/MariaDB site, installs a source
copy of the plugin, and proves that activation/repair create or repair schema
without indexing pre-existing content or creating demo posts. It also proves
deactivation clears scheduled queue processing while retaining `fts_*` data,
and uninstall clears plugin-owned operational options and pending queue state while retaining `fts_*` tables/data. Multisite lifecycle proof is explicitly not run by that smoke; the command and release evidence collector
record the multisite boundary instead of reporting a single-site pass as
network evidence.

Upgrade release evidence is available through
`tools/run-disposable-upgrade-multisite-smoke.sh` for direct-install/operator
reviews when a previous direct-install package is supplied. That smoke creates
an isolated Docker WordPress/MariaDB multisite network, network-activates the previous direct-install package, indexes generated fixture content, upgrades to
the current package, checks schema version/status, proves repair idempotence after upgrade, checks search continuity and queue health after upgrade, creates
an additional disposable site, proves that site's seven `fts_*` tables use its
own table prefix, proves subsite indexing/search/queue/repair behavior, proves
the WordPress site-deletion table filter contributes the target site's FTS
tables, and deletes generated fixture content before the disposable stack is
removed. The release evidence collector only passes this lane when the decoded
report records `multisite_evidence.status` as `passed`.

The release evidence collector can also build the previous direct-install
package from a local Git ref/SHA with `--previous-direct-package-ref=REF`. That
path resolves only local history, rejects the current target commit, requires
the release builder and Composer lockfile at the previous ref, archives package
source paths into temporary storage, and runs the previous ZIP build with
isolated Composer home/auth, an existing local Composer package cache when
available, network access disabled, and credential-capable environment variables
scrubbed before the historical builder or nested Composer process can inherit
them. Previous refs containing Composer auth files such as `indexer/auth.json`
or `indexer/.composer/auth.json` are rejected before checkout/archive, so the
Docker upgrade wrapper is not invoked for those refs.

## Reindex Strategy

Run a full reindex after first activation, after large content imports, and
after changing language or analyzer behavior.

```sh
wp fts reindex --post_type=post --batch_size=500
```

Use a smaller batch size on shared databases:

```sh
wp fts reindex --post_type=post --batch_size=100
```

Use `--limit` for smoke tests and staged rollouts:

```sh
wp fts reindex --limit=50 --batch_size=10
```

The reindexer pages through posts by ascending ID. A repeated reindex of
unchanged content is cheap at the document level because the content hash lets
the indexer skip unchanged documents, but the command still reads and counts the
posts it processes. The hash also includes the analyzer/index signature, so
stemming, language-pipeline, or other analyzer behavior changes rewrite existing
documents even when their source content is unchanged.

Important current behaviors:

- `--post_status` defaults to `publish,draft,pending,future,private`.
- `--post_type` defaults to `post`.
- comma-separated values are accepted, for example `--post_type=post,page`.
- language can be forced with `--lang=pl-PL`.
- full reindex and runtime post-save indexing share the same extractor path for
  title, content, excerpt, taxonomy terms, selected custom fields, optional
  rendered block deltas, field boosts, and stored product metadata.
- save/insert hooks queue bounded indexing work, and status/delete/trash hooks
  tombstone posts that leave the supported front-end or admin-searchable status
  scopes.
- WordPress taxonomy relationship, term edit/delete, and selected post-meta
  hooks coalesce affected post IDs into the same bounded queue. Direct database
  writes need explicit dependency invalidation or a scoped reindex.
- password-protected, trashed, deleted, unsupported-status, or otherwise
  non-searchable posts are tombstoned instead of left visible in search results.
- posts that no longer match a later manual reindex scope should still be
  deleted or tombstoned explicitly if no WordPress status/delete hook fires.

Delete a known document from the index:

```sh
wp fts delete 123
```

For a cleanup list generated by WordPress, pipe post IDs into delete:

```sh
wp post list --post_status=trash --format=ids | xargs -r -n1 wp fts delete
```

The MySQL backend stores postings as rows and applies document-frequency deltas
instead of rewriting whole per-term blobs during normal indexing. This removes
the previous whole-blob lost-update failure mode for different documents, but
WP-CLI reindex, delete, optimize, and reset jobs now coordinate through the
plugin's shared writer lock. If one of those commands reports lock contention,
no overlapping writer was started; check `wp fts status` and try again after
the active batch finishes. `wp fts status --format=json` reports
`lock_state=none`, `active`, or `expired` with sanitized mode/start/expiry
fields, bounded age/expiry timing, and operator advice. An active lock means
another writer is currently preventing overlap. An expired lock means a stale
payload remains; the next indexing writer attempt replaces it automatically
before writing. Recurring expired locks usually point to interrupted or fatal
indexing jobs and should be investigated through the latest batch/failure
diagnostics. There is intentionally no force-unlock control in this slice: a
manual delete path could clear an active writer's safety token and allow
overlapping writes. The lock is option-backed rather than an external
distributed lock, so avoid overlapping full reindex, delete, optimize, and reset
jobs until the target environment has been load tested.

## Index Reset

Use reset when an operator needs to clear only the derived Language FTS index
and runtime indexing state while keeping WordPress content and plugin
configuration:

```sh
wp fts reset-index --yes
wp fts reset-index --yes --format=json
```

The command requires `--yes`. Without confirmation it reports
`confirmation_required` and does not mutate FTS storage or plugin options. With
confirmation it clears the plugin-owned FTS documents, postings, document
lengths, document metadata, term rows, collection metadata, pending queue, stale
debt, failed-item recovery metadata, and stale failure/latest-batch state. It
preserves WordPress posts, post meta, terms, unrelated options, plugin settings,
analyzer-pack options, schema version, and the existing `fts_*` table contract.
It does not change uninstall behavior; uninstall remains conservative and
data-retaining.

If another index writer holds the shared lock, reset reports `skipped_locked`
and leaves storage, queue, stale debt, failure state, and the lock untouched.

After reset, repopulate the index with bounded batches or a scoped reindex:

```sh
wp fts process-batch --batch_size=100 --time_budget=20
wp fts reindex --post_type=post,page --post_status=publish --batch_size=200
```

## Search Operation

Run searches with an explicit language whenever possible:

```sh
wp fts search "release notes" --lang=en-US --limit=10
```

Use `--mode=AND` when every analyzed term must match:

```sh
wp fts search "release notes" --mode=AND --lang=en-US
```

The output contains `doc_id` and `score`. Fetch the WordPress post separately:

```sh
wp post get 123 --fields=ID,post_title,post_status,post_type
```

WP-CLI search can also filter by stored post metadata and return snippets from
bounded extracted text:

```sh
wp fts search "release notes" --post_type=post,page --post_status=publish --after=2026-01-01 --snippet
```

For scripts and runbooks, emit the paginated search payload as JSON:

```sh
wp fts search "release notes" --lang=en-US --limit=10 --format=json
```

To diagnose why a result matched or ranked, add bounded read-only search
explain data:

```sh
wp fts search "release notes" --lang=en-US --explain --format=json
```

The explain payload includes the analyzed query plan, prefix and fast-mode
state, a bounded fast-mode decision reason, scoring counts, recency boost
details, storage metadata availability, and bounded per-result match details,
including field-specific matches when field metadata is available. It does not
mutate the index and should be treated as operational diagnostics, not
a stored audit history.

When support needs one bounded payload that combines query explain output with
the surrounding operator state, use the diagnostic bundle:

```sh
wp fts diagnose "release notes" --lang=en-US --format=json
```

`wp fts diagnose` is read-only. It does not index content, drain queues, repair
schema, mutate options, write persistent telemetry, or create log records. The
JSON bundle includes a schema/tool identifier, the bounded query, effective
query arguments, `operator_status`, explain-enabled search results, and a
concise summary with storage, fast-mode, provider-compatibility, language-pack,
lock, stale-index, pending-work, and result-count signals. Use it for support
and debugging. It is not persistent telemetry and does not certify public
submission readiness or third-party search-provider compatibility.

Use a recency boost only when operators want newer posts to receive a small
query-time ranking lift from indexed `post_date_gmt` metadata:

```sh
wp fts search "release notes" --recency_boost=0.3 --recency_boost_half_life_days=30
```

The boost is disabled by default. Changing the strength or half-life does not
require a reindex when post dates are already indexed, and explain/debug
diagnostics report whether the boost applied.

The plugin registers a `wp-fts/v1/search` REST helper and a PHP
`WP_FTS_Plugin::search()` helper. Both rely on WordPress post visibility checks
so public results are returned only for readable posts; private results require
the current user to pass `read_post`. By default both helpers return only
visible ranked rows:

```sh
curl 'https://example.test/wp-json/wp-fts/v1/search?q=release%20notes'
```

The default REST response shape is:

```json
{"results":[{"doc_id":123,"score":1.25}]}
```

The default PHP helper remains:

```php
$rows = WP_FTS_Plugin::search('release notes', ['limit' => 10]);
```

Operators who can `manage_options` can request the same bounded structured
explain diagnostics used by WP-CLI, Debug Bar/Health, and the Sandbox:

```sh
curl -H 'X-WP-Nonce: ...' \
  'https://example.test/wp-json/wp-fts/v1/search?q=release%20notes&explain=1'
```

Authorized REST explain responses add an `explain` object beside `results`.
That object contains bounded `query_plan`, `fast_mode`, `scoring`, recency, and
per-result match diagnostics. Per-result explain rows are filtered to the
returned visible result IDs. Public or unauthorized `explain=1` requests keep
the normal `results`-only response and do not expose diagnostics.

PHP callers that need parity can use the explicit opt-in helper:

```php
$payload = WP_FTS_Plugin::search_with_explain('release notes', ['limit' => 10]);
```

For callers without `manage_options`, `search_with_explain()` returns normal
visible `results` with `explain_available` set to `false` and no diagnostic
payload.

## Admin Settings And Diagnostics

Settings > Full-Text Search exposes Health, Settings, Sandbox, Indexed content,
and Analyzer packs tabs to users who can `manage_options`. The Settings tab can
toggle automatic indexing, public search replacement, wp-admin Posts search
replacement, search-provider compatibility, highlighting, snippets, prefix
matching, result limits, language fallback, and indexed post types. Use the
documented options and filters for analyzer pack paths and custom field
selection.

The Health tab shows schema status, stored and expected schema versions, safe
indexing lock state, indexing counts, stale reindex debt progress, and the
latest batch summary. Its lock row distinguishes `None`, `Active`, and
`Expired`. Active-lock advice means another writer is running and operators
should retry shortly or check `wp fts status`; expired-lock advice means the
stale payload will be replaced automatically by the next indexing writer, while
recurring expired locks indicate interrupted or fatal indexing jobs. Its repair
button runs schema/table repair only; it does not index content, create sample
posts, drain the queue, or force-unlock the shared writer lock.

The Health and Settings tabs also show the known search-provider advisory near
the compatibility controls. The advisory is meant to help operators decide
between "Prefer Language FTS" and "Keep another search provider's results"; it
is not live certification that a detected Jetpack, SearchWP, Relevanssi, or
ElasticPress installation has been tested end to end on the current site.

Request-level diagnostics are collected only for authorized or debug-enabled
contexts: a `manage_options` user, `WP_FTS_DEBUG`, standard `WP_DEBUG`, or the
`wp_fts_debug_enabled` filter. When Debug Bar is active, the plugin registers an
FTS panel. Without Debug Bar, authorized users can see the same bounded request
diagnostics on the Health tab. These traces live in memory for the current PHP
request and are capped; they are not persistent logs or historical telemetry.

Successful front-end and wp-admin Posts replacements include an explain summary
when diagnostics are active. The trace identifies the storage backend, analyzed
query languages, query surfaces with analyzed storage terms/keys, prefix
expansion count, fast-mode source, reason, and cap, candidate rows/docs scored,
exact versus approximate totals, performance-budget status, and bounded
per-result match reasons for the returned page. The Performance budget row
compares the existing trace timings against the configured total and
`storage/search`
thresholds, then reports `within_budget`, `over_budget`, `disabled`, or
`unavailable` with the crossed phases when a budget is exceeded. Bailout traces
without timing data are reported as unavailable rather than fast. Bailout traces
still keep their readable reason so operators can
distinguish unsupported query shapes, disabled replacement settings, and
successful FTS ownership without creating diagnostic content.

For front-end and wp-admin Posts search replacement, diagnostics also show a
bounded `posts_pre_query` hook pipeline around Language FTS: callback labels,
priorities, before/same/after counts, and the FTS priority. This inspects hook
registration state only; it does not call third-party provider APIs or include
provider result payloads. A read-only late observer also records request-local
final ownership for traced searches after later `posts_pre_query` callbacks run.
It returns the incoming posts unchanged and stores only bounded status, counts,
post ID samples, and compact hashes so operators can see whether Language FTS
survived, a later callback changed the FTS output, the null search path was
replaced by FTS, or coexistence mode respected an earlier provider result. This
evidence is not persistent telemetry and does not certify Jetpack, SearchWP,
Relevanssi, ElasticPress, or custom providers end to end.

The same trace includes bounded, redacted SQL query summaries only when the
environment already collects query data in `$wpdb->queries`, such as a site with
`SAVEQUERIES` enabled or a compatible debug/test database object. The plugin
does not enable `SAVEQUERIES` automatically, does not require Query Monitor or
Debug Bar, and does not write persistent SQL logs. When `$wpdb->queries` is not
available, diagnostics report SQL capture as unavailable instead of reporting a
false zero-query trace.

## Optimize And Repair

Deletes are tombstones until compaction. Run optimize after bulk deletes or after
a large content cleanup:

```sh
wp fts optimize
```

Optimize removes tombstoned document IDs from posting rows, deletes empty term
rows, purges tombstoned document rows, and rebuilds per-language metadata from
active document lengths.

Use the Health-tab repair action or repair command when schema state is missing
or stale and you do not want to index content:

```sh
wp fts repair
```

Use the Health-tab queue processor control or schedule command when status
reports `queue_processor_schedule.status=missing`:

```sh
wp fts schedule-queue
wp fts schedule-queue --format=json
```

This only restores a future background event. It does not index content in the
current request or command. If `cron_runner.status=external_required`, confirm a
host/system cron trigger for `wp-cron.php`; schedule recovery by itself does not
make traffic-triggered cron run when `DISABLE_WP_CRON` is enabled.

Use one manual lifecycle batch when queue, backfill, or stale reindex work
should advance under operator control without draining the whole site in one
command:

```sh
wp fts process-batch --batch_size=100 --time_budget=20
```

Each batch processes queued post updates first, then missing eligible content,
then uses any remaining batch and time budget to reindex stale active indexed
rows. Stale processing uses a deterministic post-ID cursor bound to the current
index profile hash. If the profile changes before completion, the cursor
restarts for the new profile instead of clearing debt for the old one.

Repeat `wp fts process-batch` until `wp fts status` reports `has_more` as false
and `stale_debt_active` as false if you intentionally want to catch up through
bounded operator steps.

If `wp fts status` reports `last_batch_failures` greater than zero, inspect the
bounded latest-failure fields (`last_failed_post_id`, `last_failed_post_title`,
`last_failed_at`, and `last_error`) and the durable recovery list:

```sh
wp fts failed-items
wp fts failed-items --format=json
wp fts failed-items --post_id=123 --format=json
```

Failed items enter conservative backoff first. After repeated failures they are
quarantined, and automatic queue, backfill, and stale-debt passes skip them so
unrelated indexing can continue. Fix the underlying post or environment problem
before retrying. Retry marks selected recovery records retryable and queues them
for a later bounded pass; it does not index content directly:

```sh
wp fts retry-failed-item 123 --format=json
wp fts process-batch --batch_size=25 --time_budget=20
```

Clearing removes only failure-recovery metadata. It does not delete WordPress
content, remove indexed rows, or clear an already queued retry:

```sh
wp fts clear-failed-item 123 --format=json
```

For bulk operator recovery, use `--all --limit=<n>` with a small limit. Failure
messages are intentionally concise and do not include stack traces or raw SQL.

If collection metadata or snippet metadata looks wrong, run:

```sh
wp fts optimize
wp fts reindex --post_type=post
```

If posting rows or tables are corrupt, restore the database from backup or
rebuild the index tables from WordPress content in a maintenance window.

## Backup And Restore

The FTS tables are derived from WordPress posts, but they are still part of the
database state. Include them in normal database backups when you need fast
restore:

```sh
wp db export before-fts-maintenance.sql
```

Restore expectations:

- Restoring WordPress content without the matching `fts_*` tables is acceptable
  if you run `wp fts reindex` afterward.
- Restoring `fts_*` tables without matching `wp_posts` can produce stale results.
- A point-in-time restore should keep `wp_posts`, `wp_postmeta`, multilingual
  plugin tables, and `fts_*` tables from the same backup point, or it should
  rebuild the FTS index after restore.
- Composer dependencies are not stored in the database; restore the plugin files
  and vendor dependencies separately from the DB.

## Performance Sizing Notes

Current storage is intentionally simple and has known scaling limits:

- Each matching term/document pair is stored as a row in `fts_postings`.
- High-frequency terms create many posting rows and remain the most expensive
  to search, delete, and optimize.
- `optimize` scans all terms when tombstones exist.
- Per-language metadata, document lengths, and product metadata are small
  compared with postings on most sites.

Practical guidance for this branch:

- Reindex off peak on large sites.
- Start with `--batch_size=100` to `--batch_size=500`, then adjust based on DB
  load.
- Treat a `wp fts reindex`, `wp fts delete`, `wp fts optimize`, or
  `wp fts reset-index` lock warning as a safe skip; the command did not mutate
  the index while another writer was active.
- Keep explicit `--post_type` and `--post_status` scopes narrow.
- Use `--limit` for smoke tests before running a full reindex.
- Monitor table sizes for the active prefix, especially `*_fts_postings`.

Example table inspection:

```sh
wp db query "SHOW TABLES LIKE '%\\_fts\\_%';"
wp db query "SELECT COUNT(*) AS terms FROM wp_fts_terms;"
wp db query "SELECT COUNT(*) AS postings FROM wp_fts_postings;"
wp db query "SELECT COUNT(*) AS docs FROM wp_fts_docs WHERE is_deleted = 0;"
```

Replace `wp_` with the actual WordPress table prefix.
