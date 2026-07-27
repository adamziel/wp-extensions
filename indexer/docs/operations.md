# Operations

Language FTS stores derived search data in custom relational tables. Treat
the index as rebuildable from WordPress content, but back it up when fast
restore matters.

## Schema Creation And Repair

Activation and explicit repair create or repair the index tables, store the
schema contract version, and schedule the bounded runtime queue processor.
Ordinary search/readiness and worker batches trust durable version/health state
and never inspect or repair physical schema.

On multisite, activation, the Health-tab repair action, and `wp fts repair`
operate on the current site's table prefix. When WordPress initializes a new
site, the plugin switches into that site, creates or repairs the four FTS tables,
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

Normal Health/status trusts stored readiness state. It does not run `SHOW
TABLES`, verify indexes, or count the eligible WordPress corpus and whole
document table. JSON reports `schema_status=not_checked` and
`schema_verification=not_run` plus bounded pending post/scope work counts,
whether each count is exact or a lower bound, and the active reconciliation
cursor. Normal status uses one bounded aggregate over indexed `fts_work` state.
The explicit support snapshot and
`wp fts diagnose <query>` add bounded physical schema verification and label it
with `schema_verification=physical`. They retain the same bounded queue and
reconciliation fields; no operator diagnostic exhaustively counts WordPress
posts or FTS documents. Status, support snapshots,
and diagnose remain read-only when the physical schema is damaged: they do not
repair it, enqueue work, or schedule the queue processor.

On MySQL/MariaDB, explicit physical verification uses one tiny capability read
and one table-bounded `information_schema` snapshot for all four FTS tables and
all three supporting core-table indexes. Including the bounded work-status aggregate,
`operator_status(true)` and a support snapshot each execute exactly three plugin
statements. A healthy diagnose with page hydration executes six: those three,
then plan, rank, and hydration. SQLite correlates `sqlite_schema` with
table-valued PRAGMAs and replaces the two MySQL metadata reads with one.

Repair itself is a writer. The Health action and `wp fts repair` acquire the
same lease as indexing and optimize; under contention they report
`skipped_locked` and perform zero physical probes or DDL.

Status includes `index_profile_hash`, `accepted_index_profile_hash`,
`reconciliation_active`, `profile_reconciliation_pending`,
`foreground_owner_guard_blocked`, and the durable `fts_work` post count, scope
count, and scope cursor. A profile mismatch means
index-time configuration changed after the last accepted profile. The scope
row and the post generations it expands are the reconciliation state; there is
no parallel option cursor or independently maintained debt counter.

Foreground canonical-write boundaries have two durable states, neither of
which is an ordinary retry or worker lease. A pre-hook that holds the shared
owner lock writes `guarded`; a pre-hook that cannot prove that capability writes
operator-only `fenced`. One primary-key UPSERT advances the post generation and
stores the current request token before canonical SQL. The matching post-SQL
hook can promote only that token and generation; a later boundary supersedes it
atomically, so a stale completion cannot expose the old projection. Same-post
metadata writes retain one boundary until request shutdown. A crashed request
needs no database-session cleanup or separate recovery API. The `guard:*` token
is retained for ownership CAS and diagnostics, but worker eligibility never
parses or scans token text. A free exclusive probe adds the separately indexed
`guarded` state to the fixed candidate arms. A busy or unavailable probe omits
both protected states. `fenced` is never auto-claimed: no later file observation
can prove that the request which failed to acquire a guard has exited.
Process death closes the descriptor unless application code deliberately forks
after guard acquisition and leaves the inherited descriptor open; after final
close, the ordinary bounded worker claim replaces the token by
`(job_key,generation)` CAS. A second post-write probe catches an owner that
started between the first probe and claim SQL and re-fences only the worker's
still-owned generations. If that second probe cannot inspect the path, the
synthetic guarded fence stays closed and Health stays unhealthy until the path
is repaired. If the initial foreground acquisition cannot open the file, the
request instead writes a finite unmarked fallback fence and latches search
takeover unhealthy so WordPress core search remains authoritative. That row
becomes due at its finite deadline but remains closed. Its normal post-SQL hook
promotes it to ready work; if that request dies first, the distinct Health latch
requires a quiesced operator reset. A free `next_available_at()` query omits
`fenced` entirely, so operator-only debt cannot create a recurring cron wakeup
or starve `guarded` work behind a candidate limit. Operators should not edit
these rows or lock files manually.

Every PHP web, cron, and WP-CLI process connected to the same database must see
the same lock-file inode with working POSIX `flock()` semantics. MySQL/MariaDB
sites default to a deterministic mode-`0700` private runtime subdirectory below
`WP_CONTENT_DIR/uploads`; the
supported SQLite drop-ins use the directory of canonical `FQDB`. Define an
absolute `WP_FTS_FOREGROUND_LOCK_DIR` when those defaults are not shared across
SAPIs. Multi-node local disks and filesystems with ineffective advisory locking
do not satisfy this deployment contract. The lock file is never renamed or
unlinked. A foreground acquisition stops retrying an unexpected exclusive
holder after a 50-millisecond monotonic deadline and fails closed, so a stuck
external lock cannot hang canonical WordPress writes. Guard acquisition adds
no database statement.

Status also includes `failure_recovery`, a bounded read-only history of recent
item-level indexing failures. The records include sanitized post IDs, bounded
titles/labels, first/latest failure timestamps, attempt counts, source/mode,
redacted error summaries, and retry state. The history is capped by item count
and JSON byte size; it does not store post bodies, raw SQL, stack traces,
secrets, or unbounded option payloads.

Status also includes `queue_processor_schedule`, a read-only view of the
`wp_fts_process_index_queue` WP-Cron event. `scheduled` means WordPress has a
queue processor event waiting and reports the next UTC run time and delay.
`missing` means bounded status inputs show pending queue or reconciliation
work but no queue processor event is scheduled. Use the Health tab's
queue processor control or `wp fts schedule-queue` to restore the future
background event, then check for disabled or stalled WP-Cron. Schedule recovery
only schedules a later background run; it does not index content immediately.
`wp fts process-batch --batch_size=100 --time_budget=20` remains the manual
one-pass fallback while cron is investigated. `not_needed` means no pending
indexing work was detected, and `unavailable` means the current non-WordPress
context cannot inspect cron helpers.

Each worker persists its one required successor while it still owns the shared
writer lease. A `false` WP-Cron result is not treated as success: a manual batch
returns `successor_schedule_failed=true` with the matching stop reason, while a
WP-Cron callback throws `WP_FTS_Index_Successor_Schedule_Failed` only after its
durable mutations are complete and its lease is released. The worker does not
retry or schedule after lease release. Pending work remains visible as the
`missing` schedule state above, where the Health control or `wp fts
schedule-queue` provides the bounded recovery path. The same visible failure
applies when a cron callback skips an active writer lease and cannot persist its
follow-up: the callback leaves both the queued work and the current lease
unchanged.

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
language when a locale such as `en-US` is covered by `en`, the disabled
query-fanout invariant, gzip/runtime-pack availability, bounded active runtime
pack summaries, bounded unsupported/license-blocked language summaries, and an operator
recommendation. Table output keeps the nested row and adds concise flattened
rows such as `language_pack_site_language`,
`language_pack_runtime_support`, `language_pack_active_runtime_languages`,
`language_pack_gzip_status`, and `language_pack_recommendation`. This status is
advisory only: it does not install analyzer packs, change analyzer options,
create posts or demo content, drain queues, run indexing, or reindex existing
content.

Activation creates or repairs the schema. To queue content reconciliation, run:

```sh
wp fts reindex --post_type=post --format=json
```

This command returns after one durable filtered scope UPSERT. WP-Cron performs
the bounded indexing passes; `wp fts process-batch --batch_size=100
--time_budget=20 --format=json` performs one such pass manually.

The relational backend creates four tables under the active WordPress table prefix:

- `fts_terms`: typed binary dictionary keys and global document frequency;
  `kind=0` is analyzed lexical identity and `kind=1` is a complete normalized
  source surface used by one indexed prefix range, not a row per proper prefix.
- `fts_postings`: compact rows keyed by `(term_id, post_id)` with precomputed
  field impact and a post-first probe index.
- `fts_documents`: one bounded source-hash/snippet row per live document.
- `fts_work`: coalesced post generations, retry timing, and leased worker
  ownership for pending indexing work.

Physical verification compares the complete current table contract. Because the
index is derived, repair replaces an invalid current FTS table rather than trying
to preserve its rows. Creation then uses WordPress `dbDelta()` when available or
raw `CREATE TABLE` statements otherwise. Database write failures are surfaced
with the failed operation name so activation, repair, and runtime indexing do
not silently continue after a schema or storage error.

During multisite site deletion, the plugin contributes the target site's four
`fts_*` table names to WordPress table discovery so core can clean them up with
the deleted site. Deactivation is reversible: it clears scheduled work and
retains the index. Uninstall is deliberately destructive: for each site it uses
one idempotent `DROP TABLE IF EXISTS` statement covering the four current tables
and eight deterministic reset-generation names, then removes operational
options. Before dropping tables, it removes only exact plugin-namespaced
supporting core-index definitions; same-name conflicting definitions are left
untouched. Cleanup uses the same per-site writer lease as indexing and repair.
If that lease is active, uninstall leaves that site's tables and options
untouched and fails so the operator can retry after the writer finishes; an
expired lease is safely taken over. The lease stays owned until table, schedule,
and non-lease option cleanup is complete, so another writer cannot enter the
partially removed site. Before the DROP, uninstall persists an exact
non-autoloaded one-byte fence (`1`) and retains it after successful or partial
cleanup. Preloaded repair, indexing, save-hook, and scheduling callbacks do no
work behind it. Reinstalling the ZIP inactive does not clear the fence. An
uninstall retry may cross it idempotently; otherwise only explicit activation
clears it under a writer lease, repairs the four current tables, and queues
reconciliation. Network activation carries one bounded capability through its
site pages, so an event preloaded before a later uninstall cannot clear the new
fence. A multisite uninstall still cleans uncontended siblings
and reports the number and first ID of blogs that need a retry. Multisite
discovery holds at most 100 site IDs at a time. Uninstall does not create or
delete WordPress posts, terms, users, attachments, uploads, analyzer packs,
generated packs, or release artifacts.

The disposable lifecycle report is available through
`tools/run-disposable-lifecycle-smoke.sh` for direct-install/operator reviews.
That smoke creates an isolated Docker WordPress/MariaDB site, installs a source
copy of the plugin, and proves that activation/repair create or repair schema
without indexing or changing pre-existing content. It also proves
deactivation clears scheduled queue processing while retaining `fts_*` data,
and uninstall removes plugin-owned current/reset-generation tables and operational
options while retaining only the exact non-autoloaded one-byte uninstall fence.
The smoke network-activates the plugin, creates a real subsite, seeds all eight
deterministic reset-generation table names on both sites, and requires all
twelve owned tables to be absent from both site prefixes after uninstall. It then
installs the same source-bound ZIP inactive, proves the two fences still block
schema recreation, network-reactivates it, runs the one-site provisioning event,
and requires both fences absent plus exactly four current and zero reset-generation tables
per site. Its command and release evidence collector reject missing multisite,
table-removal, fence-shape, or reactivation proof.

## Reindex Strategy

Run a full reindex after first activation, after large content imports, and
after changing language or analyzer behavior.

```sh
wp fts reindex --post_type=post --format=json
```

Reindex accepts scope options only. Worker controls such as `--batch_size` and
`--time_budget` belong to `wp fts process-batch`. Reindex canonicalizes its
filters, stores exactly one durable scope, schedules the worker, and returns
without selecting source rows or acquiring the index-writer lease. WP-Cron then
discovers at most 100 post IDs per worker pass.

Use a smaller one-pass worker batch on shared databases:

```sh
wp fts process-batch --batch_size=100 --time_budget=20 --format=json
```

Use `--limit` for smoke tests and staged rollouts:

```sh
wp fts reindex --limit=50 --format=json
```

The shared worker discovers posts by ascending-ID keyset. No reindex invocation
selects the corpus into PHP or emits one corpus-sized queue statement: each pass selects and
materializes at most 100 post IDs. A requested `--limit` is decremented in the
same transaction that advances the scope cursor. A repeated reindex of
unchanged content is cheap at the document level because the content hash lets
the indexer skip unchanged documents. The hash also includes the analyzer/index signature, so
stemming, language-pipeline, or other analyzer behavior changes rewrite existing
documents even when their source content is unchanged.

Important current behaviors:

- `--post_status` defaults to `publish,draft,pending,future,private`.
- `--post_type` defaults to `post`.
- comma-separated values are accepted, for example `--post_type=post,page`.
- each filter is capped before splitting at 4,096 bytes, 32 distinct values,
  and 64 bytes per value;
- runtime reconciliation retains those supported operator-visible statuses for
  every configured searchable post type; front-end visibility still compiles
  to `publish` only.
- language can be forced with `--lang=pl-PL`.
- full reindex and runtime post-save indexing share the same extractor path for
  title, static content, batch-preloaded taxonomy terms and selected custom
  fields, field boosts, and a bounded saved-content snippet source.
- save/insert hooks queue bounded indexing work, and status/delete/trash hooks
  physically delete derived rows for posts that leave the indexed corpus.
- WordPress taxonomy relationship, term edit/delete, and selected post-meta
  hooks coalesce affected post IDs into the same bounded queue. Direct database
  writes need explicit dependency invalidation or a scoped reindex.
- password-protected, trashed, deleted, unsupported-status, or otherwise
  non-searchable posts are excluded by canonical visibility and physically
  removed when their queued generation is reconciled.
- posts that no longer match a later manual reindex scope are removed when the
  canonical reconciliation scope reaches their retained document row.

Reconcile a known missing or ineligible canonical post:

```sh
wp fts delete 123
```

For a cleanup list generated by WordPress, pipe post IDs into delete:

```sh
wp post list --post_status=trash --format=ids | xargs -r -n1 wp fts delete
```

`wp fts delete` is not a direct derived-row delete. If the ID still belongs to
an eligible canonical WordPress post, the command refuses the self-reversing
operation and tells the operator to change/delete the source post. If the source
is missing or ineligible, it queues exactly one durable post generation; the
shared worker performs the physical cleanup under its normal lease.

The relational backend stores postings as rows and applies document-frequency deltas
inside the bounded replacement transaction. Worker passes, optimize, repair,
and reset coordinate through the plugin's
shared writer lock; `wp fts reindex` only queues one scope. If a writer command
reports lock contention,
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
distributed lock, so avoid overlapping manual worker, optimize, repair, and
reset jobs until the target environment has been load tested.

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
confirmation it replaces the four current FTS document, posting, dictionary,
and durable-work relations with an empty generation, then clears pending queue
state, failed-item recovery metadata, and failure/latest-batch state. It
preserves WordPress posts, post meta, terms, unrelated options, plugin settings,
analyzer-pack options, and the existing `fts_*` table contract.
It does not change uninstall behavior; uninstall still removes every current
and deterministic reset-generation FTS table owned by the plugin.

This is also the recovery authority for an `owner guard unavailable` Health
latch. First repair the shared lock path, then stop or drain PHP web, cron, and
CLI processes that could still be inside a canonical write. While the site is
quiesced, run `wp fts reset-index --yes`; keep it quiesced until the command has
published the empty generation and queued reconciliation. Generic maintenance
never clears this latch, and a worker never guesses that a request represented
by an operator-only `fenced` row has exited.

MySQL and MariaDB reset by creating four empty `LIKE` tables and publishing all
four with one atomic `RENAME TABLE`; SQLite uses one transactional schema
rebuild. Reset therefore does not issue table-wide `DELETE` or `COUNT(*)`
statements. JSON reports the selected `reset_strategy`, normal status fields,
and `reconciliation_queued=true`; it does not scan the corpus or report removed
row and queue totals. Reset writes one complete-corpus scope and schedules one
bounded worker event after publication. Search takeover is marked pending
before the first DDL statement and remains pending until that scope
finishes and maintenance verifies the physical schema. An interrupted
publication, cleanup, scope write, or scheduling attempt fails the command and
never reports a completed reset.

If another index writer holds the shared lock, reset reports `skipped_locked`
and leaves storage, durable work, failure state, and the lock untouched.

Do not run a second manual reindex just to rebuild after reset. Reset already
queues the one unfiltered reconciliation scope that can restore readiness;
filtered CLI scopes cannot prove complete coverage. WP-Cron drains the automatic
scope in bounded pages. If an operator needs to advance one page manually, run:

```sh
wp fts process-batch --batch_size=100 --time_budget=20
```

Canonical WordPress writes that race with staging can enqueue into the retired
work generation before the atomic rename. They are not lost as content: reset
queues its complete scope only after publication, so that scope rediscovers the
then-current canonical posts. Writes after publication enqueue beside it in the
new work generation. WordPress search remains authoritative until reconciliation
completes; do not treat a successful reset by itself as a searchable empty-index
cutover.

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

The relational explain payload is deliberately flat and bounded. It reports
the storage path, logical group and resolved-alternative counts, anchor group,
whether the final term used one dictionary prefix range, the complete statement
count, cursor state, canonical page bytes, and normalized recency settings with the fixed ranking time when recency is enabled. A metadata or
snippet request therefore reports three plugin-owned statements: plan, rank,
and page-bounded hydration. A request without hydration reports two.

Explain does not run a count query or a second posting pass. It does not expose
candidate lists or counters, exact/fast modes, analyzed-language fanout, or
per-result match traces. Current visibility is already part of the ranking SQL
before its limit. Treat explain as operational diagnostics, not stored audit
history.

When support needs one bounded payload that combines query explain output with
the surrounding operator state, use the diagnostic bundle:

```sh
wp fts diagnose "release notes" --lang=en-US --format=json
```

`wp fts diagnose` is read-only. It does not index content, drain queues, repair
schema, mutate options, write persistent telemetry, or create log records. The
JSON bundle includes a schema/tool identifier, the bounded query, effective
query arguments, `operator_status`, explain-enabled search results, and a
concise summary with the one query language, flat relational plan, recency
configuration, provider compatibility, language-pack support, lock,
stale-index, pending-work, and returned-page signals. The summary contains only
fields produced by the relational plan. Its operator status performs bounded
physical schema verification and reports bounded queue/reconciliation state,
not corpus totals. A damaged schema is reported without repair or queue
scheduling. Use it for support and debugging.
It is not persistent telemetry and does not certify public submission readiness
or third-party search-provider compatibility.

Use a recency boost only when operators want newer posts to receive a small
query-time ranking lift from indexed `post_date_gmt` metadata:

```sh
wp fts search "release notes" --recency_boost_strength=0.3 --recency_boost_half_life_days=30
```

The boost is disabled by default. Changing the strength or half-life does not
require a reindex when post dates are already indexed, and explain/debug
diagnostics report whether the boost applied.

## Public REST Search

The PHP `WP_FTS_Plugin::search()` helper is always available to plugin code.
The public `wp-fts/v1/search` route is different: it is not registered until an
operator enables **Settings > Full-Text Search > Public REST search > REST
endpoint**. Leave it disabled unless a separate client actually needs anonymous
search.

Once enabled, the endpoint uses the same exact relational page as every other
plugin adapter:

- input is rejected before ranking when it exceeds 4,096 bytes, 12 logical
  groups, 12 alternatives per group, or 12 alternatives in total;
- final-word prefix matching is independently disabled by default and, when an
  operator enables it, executes one complete indexed dictionary range without
  enumerating completions into PHP;
- one planning statement, one ranking statement, and at most one page-bounded
  hydration statement return at most 50 rows plus one lookahead row;
- current post type, status, password, and dirty-generation visibility is
  applied in the ranking SQL before its limit; and
- pages use signed search-after cursors and omit a total rather than running an
  exhaustive count.

`q`, `mode`, and `lang` are strings and are never rewritten. `mode` is exactly
`OR` or `AND`; `lang` and cursors must be non-empty when present. `limit` is a
canonical integer from 1 through 50. A supplied direction requires a cursor,
and boolean parameters use their documented explicit spellings. Malformed
values return `400` instead of being trimmed, clamped, or guessed.

These structural limits prevent query-shape and round-trip explosion; they do
not make repeated anonymous traffic free. The plugin does not maintain another
database-backed response cache or rate-limit subsystem on this hot path. Keep
the route disabled when it is unnecessary, and use the host/CDN rate limiter
and response cache when public abuse control is required.

The `prefix_matching` request parameter cannot enable expansion. Operators must
enable **REST word beginnings** separately from normal site search. Invalid or
excessively complex requests return `400`; unavailable schema/index state or a
server-side search failure returns `503`.

After enabling the endpoint, call it with:

```sh
curl 'https://example.test/wp-json/wp-fts/v1/search?q=release%20notes'
```

The default REST response shape is:

```json
{"results":[{"doc_id":123,"score":125000}],"has_more":false,"next_cursor":null,"previous_cursor":null,"query_lang":"en"}
```

The PHP helper remains:

```php
$rows = WP_FTS_Plugin::search('release notes', ['limit' => 10]);
```

Operators who can `manage_options` can request the same bounded structured
explain diagnostics used by WP-CLI, Debug Bar/Health, and the Sandbox:

```sh
curl -H 'X-WP-Nonce: ...' \
  'https://example.test/wp-json/wp-fts/v1/search?q=release%20notes&explain=1'
```

Authorized REST explain responses remain subject to the same relational shape
limits and add an `explain` object beside `results`. It reports the bounded
logical-group, resolved-alternative, anchor-group, prefix-range, statement-count,
and cursor-state contract. Public or unauthorized `explain=1` requests keep
the normal response and do not expose diagnostics.

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
matching, optional public REST search, result limits, single-plan language routing, and
indexed post types. Use the documented options and filters for analyzer pack
paths and custom field selection.

The normal Health view leaves physical schema status unchecked so opening the
page adds no metadata probes. The explicit support snapshot and repair action
verify the exact physical schema. Health also shows safe indexing lock state,
bounded durable scope/post work and its keyset cursor, and the latest batch
summary. Its lock row distinguishes `None`, `Active`, and
`Expired`. Active-lock advice means another writer is running and operators
should retry shortly or check `wp fts status`; expired-lock advice means the
stale payload will be replaced automatically by the next indexing writer, while
recurring expired locks indicate interrupted or fatal indexing jobs. Its repair
button runs schema/table repair only; it does not index content, create sample
posts, drain the queue, or force-unlock the shared writer lock.

The Health and Settings tabs also show the known search-provider advisory near
the compatibility controls. The advisory is meant to help operators decide
between **Use Language FTS when providers abstain** and **Keep
provider-integrated searches on WordPress**. The first accepts only a `null`
handoff from an earlier provider; both modes preserve every non-null provider
result. The stricter mode keeps a registered third-party provider query on core
even after `null`. The advisory is not live certification that a detected
Jetpack, SearchWP, Relevanssi, or ElasticPress installation has been tested end
to end on the current site.

Request-level diagnostics are collected only for authorized or debug-enabled
contexts: a `manage_options` user, `WP_FTS_DEBUG`, standard `WP_DEBUG`, or the
`wp_fts_debug_enabled` filter. When Debug Bar is active, the plugin registers an
FTS panel. Without Debug Bar, authorized users can see the same bounded request
diagnostics on the Health tab. These traces live in memory for the current PHP
request and are capped; they are not persistent logs or historical telemetry.

Successful front-end and wp-admin Posts replacements include an explain summary
when diagnostics are active. The trace identifies the set-oriented storage
backend, logical-group and resolved-alternative counts, rare-group anchor, whether
the one final-word prefix range was present, the fixed statement count, absence
of an interactive total, recency configuration, performance-budget status,
and request timing/count context. The Performance budget row
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
provider result payloads. Same-priority/later providers, SQL clause/request
filters, and post-result membership callbacks already present at the ownership
gate keep valid queries on core before FTS ranking, with zero FTS statements.
If a callback first appears during relational execution, the ranked page is
discarded and the owned query fails closed with later result filters suppressed;
core LIKE is not run after the bounded FTS statements. The stock WordPress comment-state
`the_posts` callback is the sole membership-neutral exception. Hook inspection
is rechecked at replacement time, persisted on the query, and capped at 32
callbacks/buckets.

A late observer records request-local final ownership only for traced queries
that passed that ownership gate. It normally returns incoming posts unchanged
and stores only bounded status, counts, post ID samples, and compact hashes. If
an adapter has already published its owned unavailable reason and suppressed
result filters, the observer restores the empty fail-closed page after recording
any intervening change. The guard is re-armed behind callbacks registered during
relational execution. This is defense in depth for unexpected dynamic hook
changes, not a workaround for post-LIMIT filtering. This evidence is not
persistent telemetry and does not certify Jetpack, SearchWP, Relevanssi,
ElasticPress, or custom providers end to end.

The same trace includes bounded, redacted SQL query summaries only when the
environment already collects query data in `$wpdb->queries`, such as a site with
`SAVEQUERIES` enabled or a compatible debug/test database object. The plugin
does not enable `SAVEQUERIES` automatically, does not require Query Monitor or
Debug Bar, and does not write persistent SQL logs. When `$wpdb->queries` is not
available, diagnostics report SQL capture as unavailable instead of reporting a
false zero-query trace.

## Optimize And Repair

Transactional document writers maintain dictionary frequencies as an invariant.
Run optimize after bulk deletes or a large content cleanup to prune one bounded
page of empty dictionary rows:

```sh
wp fts optimize
```

One optimize invocation issues one indexed empty-term delete, ordered by
`term_id` and limited to 1,000 rows, plus the search-epoch transaction boundary.
It never rescans the vocabulary or postings to rebuild document frequencies.
Scheduled worker cleanup marks another pass pending when exactly 1,000 rows are
removed; an operator using `wp fts optimize` can safely rerun the command.
There is no production tombstone or per-language collection-statistics table to
compact.

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

Use one manual lifecycle batch when durable post or scope work
should advance under operator control without draining the whole site in one
command:

```sh
wp fts process-batch --batch_size=100 --time_budget=20
```

Each batch claims at most one scope generation and one bounded direct-post
batch. Scope work keyset-expands with the cursor stored on that `fts_work` row;
the resulting post generations use the same queue and fencing rules as direct
post updates. When one claim contains both kinds, a durable marker alternates
the work: the post turn yields the scope with one generation-fenced statement,
and the next collision releases the posts before advancing the scope cursor.
Advancing the cursor clears the marker. The persisted turn guarantees progress
for both kinds even while direct writes arrive continuously, keeps the complete
worker at no more than 20 statements, and prevents a scope from materializing
more than one page of queue work. If the profile changes before completion,
enqueueing the same scope key advances its generation and resets its durable
cursor and turn marker.

Repeat `wp fts process-batch` until `wp fts status` reports `has_more` and
`reconciliation_active` as false if you intentionally want to catch up through
bounded operator steps.

If `wp fts status` reports `last_batch_failures` greater than zero, inspect the
bounded latest-failure fields (`last_failed_post_id`, `last_failed_post_title`,
`last_failed_at`, and `last_error`) and the durable recovery list:

```sh
wp fts failed-items
wp fts failed-items --format=json
wp fts failed-items --post_id=123 --format=json
```

Failed items enter capped exponential backoff and remain eligible for automatic
retry; no generation becomes an operator-only dead letter. The delay tops out
at one hour, and the queue watchdog schedules the next available generation so
a quiet site does not strand it. Fix the underlying post or environment problem
before forcing an early retry. Retry resets the selected generation's attempt
count and queues it for a later bounded pass; it does not index content directly:

Documents that cross a permanent source, dependency, occurrence, or writer
safety limit are recorded as `rejected` instead. Their stale derived rows are
deleted and the owned generation is acknowledged, so poison content cannot hot
loop. Editing the canonical post queues a fresh generation; force a retry only
after correcting the rejected content.

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

If dictionary frequencies or stored snippet text look wrong, run:

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
- The post-first `post_term(post_id, term_id)` index deliberately leaves out
  mutable `impact`. Document replacement can update scores without rewriting a
  second copy of every score, which reduces rebuild writes and index growth.
  Post-first ranking probes trade that saving for a clustered-row lookup when
  they need the score; term-first broad searches already read clustered rows.
- High-frequency terms create many posting rows and remain the most expensive
  to search and rewrite.
- Exact broad OR and broad final-word prefix ranking must examine their matching
  postings. The request still uses only plan/rank/hydrate statements and PHP
  remains page-sized, but database work is proportional to those matches.
- Prefix planning reads one complete indexed surface dictionary range, sums
  its `doc_freq`, and reads no postings or completion payloads. The 20,004-term
  fixture has a 21,000-row dictionary/control ceiling. Multi-group prefix
  `AND` compares that cost with every resolved exact group and starts from the
  cheapest. For an exact anchor it compares the planned prefix posting sum with
  `anchor DF upper × 8,192`, using division so PHP integer overflow is
  impossible. The smaller/equal prefix range drives `term_identity` to posting
  `PRIMARY`; a larger prefix drives each candidate's capped posting envelope
  through `post_term` and classifies term IDs through dictionary
  `PRIMARY`. Both remain one rank statement and avoid a
  candidate×prefix-posting product. Worst-case acceptance proves range-first at
  9,900 / 103,500 / 201,000 prefix postings and candidate-first with one
  physical 8,000–8,192-posting candidate. Candidate-first ranking has a 32,768
  row ceiling and the complete three-statement search a 65,536 row ceiling.
  Conversely, a one-document prefix against a corpus-wide exact term must
  select the prefix anchor, avoid materializing the common posting range, remain
  exactly three statements, and examine at most 2,048 rows.
- `optimize` never scans high-frequency posting ranges. It removes at most 1,000
  indexed zero-frequency term rows with one maintenance statement.
- Bounded result-document rows are normally small compared with postings.

Practical guidance for this branch:

- Reindex off peak on large sites.
- Start manual `wp fts process-batch` passes with `--batch_size=100`, then
  adjust based on DB load while keeping a finite `--time_budget`.
- Treat a `wp fts process-batch`, `wp fts optimize`, `wp fts repair`, or
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
wp db query "SELECT COUNT(*) AS docs FROM wp_fts_documents;"
```

Replace `wp_` with the actual WordPress table prefix.
