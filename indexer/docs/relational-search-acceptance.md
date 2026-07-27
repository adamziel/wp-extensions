# Relational Search Acceptance

This document is the release contract for the custom WordPress/PHP relational
full-text search backend. It deliberately does not use MySQL `FULLTEXT` or an
external search service. The supported production envelope is small and medium
sites with up to 100,000 searchable posts on a low-end WordPress host backed by
MySQL or MariaDB; larger installations should use a dedicated search service.
WordPress Playground's
SQLite adapter remains a functional single-request smoke target, not a claimed
multi-request production-concurrency backend. The generation-CAS mutation proof,
50,000-document scale lane, and 100,000-document boundary lane run on
both supported database families with their real relational storage.

A change passes only when the machine-readable real-database evidence described
below passes. A missing dependency, `SKIP`, `PENDING`, timeout, OOM, absent
metric, or incomplete report is a failure.

## Search-path invariants

Every production WordPress PHP, REST, front-end, admin, Sandbox/AJAX, and WP-CLI
search path uses the relational storage contract and must satisfy all of these
invariants.

1. Analyze the query once without SQL.
2. Preserve one logical group per source occurrence. Surface, lemma, stem, and
   prefix alternatives contribute at most one `MAX` score to that group.
3. Route each occurrence to one primary language plan. Activating more analyzer
   packs must not add language plans or SQL statements.
4. Restrict prefix matching to the final group and compile it as one indexed
   dictionary range. Never enumerate completions into PHP or per-term SQL.
5. A ready search executes at most three plugin-owned statements: bounded dictionary plus
   singleton work/epoch/scope-control planning, relational ranking/top-K, and
   page hydration. The plan statement's complete physical relation allowlist is
   `fts_terms` plus `fts_work`; calling it dictionary-only would omit the
   control rows that authenticate readiness and reject pending scopes. An impossible mandatory term
   executes at most one statement. That planning statement must still read the
   mutation epoch and authenticate any supplied cursor before the impossible
   result returns; an analyzer-empty cursor request is rejected before SQL. An
   unexpected database failure is a separate fail-closed control path. The
   failed stage is terminal: no later search stage or core `LIKE` fallback may
   run. The request then executes two to four bounded option/cron controls to
   revoke publication, persist unhealthy state, reload invalidated option state,
   and retain one repair event. A plan failure therefore executes at most five
   plugin-owned statements, a rank failure at most six, and a hydration failure
   at most seven. These stage-aware ceilings include the successful search
   statements that necessarily preceded the failed stage; they do not relax the
   three-statement ceiling for a successful ready search.
6. Keep statement count unchanged at 2,000, 50,000, and 100,000 documents, with
   one pack or all distributable packs active.
7. Execute no SQL in term, alternative, prefix, language, candidate, or result
   loops. Generate no candidate-sized `IN` list or posting-subquery-per-term
   `UNION`.
8. Apply current `wp_posts` type, status, and password visibility and pending
   work exclusion before score ordering and `LIMIT`. Broad OR, single-group,
   and prefix ranking must compact posting arms first, then apply exactly one
   outer derived-document visibility join and one covering core-post visibility
   join before ordering; it may not repeat `d_exact_match` or `d_prefix_match`
   visibility inside posting arms.
9. Return at most `page_size + 1` ranking rows and hydrate/snippet at most
   `page_size` rows. Warm latency samples use a 20-row page. The separate
   terminal-oracle boundary exhaustively traverses both exact and prefix forms
   of the construction-known `visibilityprobe` set at the public maximum of 50.
   That set contains 600 / 10,200 / 20,200 visible rows in the 2k / 50k / 100k
   profiles. Every full page must execute plan+rank+hydrate while hydrating a full 50-row page,
   rather than taking an easier partial-page path. This proves cursor membership,
   order, and terminal-page behavior over thousands of rows without
   turning validation into repeated full-corpus ranking. Corpus-wide exact and
   20k-completion prefix terms remain in the separate warm latency workload.
10. Return `has_more` and a stable search-after cursor. Interactive search has no
    synchronous exact total and generates no deep `OFFSET`. Cursor fingerprints
    include the blog's physical index namespace, and hydration cursors advance
    across every inspected rank row even when an old oversized canonical row
    cannot be transported.
11. Leave valid WordPress query shapes with unsupported SQL membership,
    projection, ordering, page-size, or numbered-pagination constraints on core
    WordPress search. In particular, arbitrary PHP visibility callbacks and
    numbered wp-admin pages cannot participate in the relational path. Core-owned
    membership includes title/menu-order/comment/password/parent constraints,
    non-default or archive page-size overrides, meta/tax/date/ID arrays, frontend
    permission scopes, and requested post-type query vars that core maps to a
    slug predicate only after `pre_get_posts`. Quoted phrases and token-leading
    exclusion syntax such as `apple -banana` also remain on core because the
    relational analyzer has no phrase or NOT representation; internal hyphens
    such as `e-mail` remain relationally eligible. Once FTS owns an
    otherwise-supported shape, that policy/shape decision is evaluated once and
    retained: stale readiness or a later runtime failure remains fail-closed.
    A callback first registered after relational execution starts discards the
    ranked page, suppresses later result filters, and returns an owned empty
    page; it may not resume core LIKE after spending the bounded FTS statements.
    Fresh default scope covers post, page, and attachment so an ordinary stock
    unscoped search is eligible without narrowing WordPress's built-in search
    types. Saved custom scopes remain authoritative and stand down on unscoped
    queries whenever any current core-searchable type is absent.
    On an otherwise FTS-compatible, enabled main query shape, malformed
    original `WP_Query` search input is retained as an owned-invalid marker even
    after core normalizes `query_vars['s']`, and malformed or oversized adapter
    inputs are rejected before core search. Unsupported membership shapes,
    REST requests, secondary queries, and policy-disabled replacements remain
    core-owned and retain their original `s` even when an irrelevant FTS cursor
    or auxiliary value is malformed. The WordPress query adapter follows core's
    1,600-byte `s` ceiling; direct plugin/REST PHP search retains the separate
    4,096-byte public search ceiling.
12. Perform no `SHOW`, `DESCRIBE`, `information_schema`, schema inspection, or
    repair work during normal readiness/search.
13. Keep every valid worst-case search statement at or below 32 KiB. Ordinary
    PHP allocation, live RSS, and Linux high-water RSS deltas stay at or below
    16 MiB. The distinct 3.8-MiB maximum-valid canonical page stays at or below
    24 MiB. PHP peak and absolute Linux `VmHWM` stay at or below 128 MiB.
14. Reject source text above 2 MiB, more than 20,000 analyzed occurrences,
    more than 4,096 distinct terms per document, a lexical run above 4 KiB,
    query text above 4 KiB, or a query plan above 12 groups/12 alternatives
    before an unbounded value, posting collection, or SQL statement is materialized.
    Plain unquoted ten-to-twelve-group input deliberately keeps the relational
    twelve-group semantics instead of core's fallback that collapses more than
    nine parsed terms into one sentence; phrase and exclusion syntax never uses
    that divergence because it stays on core.
15. Resolve exact dictionary identities from a constant relation of at most
    twelve requested `(lang, kind, term)` tuples through the unique
    `(lang,kind,term)` identity index. The exact planning arm returns one row
    per requested identity. A final-word prefix adds one typed `kind=1` binary
    range row, so the complete planning result remains at most thirteen rows.
    Every prefix plan returns one aggregate row with the range's summed
    `doc_freq`; it reads no postings and returns no completion identities to
    PHP. Multi-group AND compares that dictionary posting-row upper bound with
    each resolved exact group and anchors the cheapest logical group. Exact
    anchors use candidate/key probes, then drive whichever is smaller: the
    non-anchor prefix's actual posting sum, or the anchor DF upper bound times
    the hard per-document posting cap. A selected prefix anchor unions its exact
    alternatives with the range-led postings once, applies visibility once, and
    probes the remaining groups by `(post_id,term_id)`.
16. Authenticate the published search-ready incarnation and profile hash, the
    authoritative active-scope profile provenance, and the singleton search
    epoch plus its random incarnation inside every plan, rank, and hydration
    statement. Every returned data row carries that control evidence;
    an otherwise empty statement returns one sentinel row that PHP validates
    and strips. Ranking and hydration must match the exact values captured by
    planning before accepting data or issuing the next statement. A mismatch,
    missing/invalid epoch row, or revoked capability raises the typed unavailable
    error without a separate control query, and cursors authenticate both
    incarnations plus the accepted profile so resetting an epoch number or
    reusing an old profile cannot revive an old cursor.

Input limits remain defense in depth. Rejecting the valid performance cases is
not an acceptable way to satisfy these invariants.

## Preserved capabilities

Golden and real-database tests must preserve exact OR/AND membership,
deterministic monotonic impact × globally comparable inverse-document-frequency
ranking, field emphasis, morphology,
Unicode normalization, source-position grouping, code-point prefix thresholds,
CJK tokenization, mixed-language documents, language overrides, current
WordPress visibility, dirty-generation exclusion, page-only snippets, and
stable cursor pagination.

An unrelated weighted field may not demote an otherwise identical body hit.
Ambiguous alternatives may not double a source token's length or group score.
All public adapters must expose the same result ordering and readiness contract,
with the stock front end returning the oracle's first site-configured page.
Real authenticated HTTP requests cover the front end, wp-admin Posts list,
Sandbox initial page, Sandbox detail AJAX, and REST; a real WP-CLI bootstrap
captures the command handler's wpdb statements. Performance Schema—not an
`explain.query_statements` assertion—attributes each web request's completed
plan/rank/hydrate statements. Every surface is repeated with a missing
dictionary table and must fail closed without a core `LIKE` query.

The wp-admin scope gate is also exhaustive across 9 post-type shapes, 13 status
shapes, and all 64 combinations of the six mapped post/page primitives:
7,488 capability combinations before result assertions. Other authors' draft and
pending rows require mapped `edit_others_posts`; future rows require both mapped
`edit_others_posts` and `edit_published_posts`, matching WordPress's scheduled
post meta-cap mapping; private rows require mapped `read_private_posts`.
Per-object grants cannot authorize a type-wide pre-LIMIT SQL scope.

Each real HTTP request receives one unpredictable request ID before WordPress
executes its SQL. A temporary MU plugin tags every wpdb statement on that
connection and emits a tagged shutdown marker. Evidence must resolve exactly
one Performance Schema thread and one terminal marker, then inspect every
completed connection event from the connection's first retained event through
that marker. Events before the MU query filter loads are retained as the
explicit untagged bootstrap prefix; every later event must carry the request
tag. The test-only filter also marks any statement whose stack enters an
installed `WP_FTS_*` class or plugin file, so provider-advisory reads and other
plugin-caused `wp_options`/`wp_sitemeta` SQL cannot hide among core bootstrap
traffic. The marked plan/rank/hydrate events must be the complete FTS search
statement set. Unrelated plugin bootstrap work before the first search event is
retained separately and cannot hide an extra statement during or after search.
Global/tag-only history queries or filters limited to `wp_posts`/FTS tables do
not prove a request's query count.

A separate cold-request artifact requires the exact current schema and all
six bounded request inputs in WordPress's autoload set: health, desired
readiness, search-ready capability, settings, analyzer
overrides, and indexed custom fields. The proof primes `alloptions`, performs
real direct-SQL CAS writes for health and search-ready capability, and requires
the replacement and restored values to be immediately visible through
`get_option()` without manual cache repair.

Four subsequent requests use new database connections and no persistent object
cache. Ready initialization executes **0 plugin-attributed statements**; an
impossible AND executes exactly plan-only (**1**); a normal nonhydrating search
executes plan+rank (**2**); and a hydrated search executes plan+rank+hydrate
(**3**). Across all four, direct plugin-caused option/site-option SQL and the
normally absent network-activation token lookup are exactly zero. The same
stack attribution runs with debug collection forced on for real front-end and
authenticated wp-admin Posts searches: each must still have exactly three
marked search statements and zero standalone option/sitemeta SQL at or after
the first search event. Hot debug formatting must use
already-computed request state; it may not run provider option probes or fully validate/read analyzer packs
merely to render a trace.
The real front-end request matrix includes both the explicitly scoped control
and a stock `/?s=...` URL with no `post_type` or `post_status`. With the fresh
`post`, `page`, and `attachment` scope, the unscoped request must return the
same independent-oracle page through exactly plan+rank+hydrate, with exactly
zero core `wp_posts ... LIKE` statements. The temporary attribution MU plugin
emits the completed main query's exact post IDs and unavailable state into a
dedicated response marker, so a theme's fallback or secondary loop cannot be
mistaken for the FTS-owned result page.

The cold front-end cache lane runs the complete WordPress 6.5+ main
`WP_Query` lifecycle, rather than calling the plugin's `posts_pre_query`
callback directly, after evicting post, metadata, taxonomy-relationship, user,
and user-metadata caches. Before returning, the plugin passes only the
already-hydrated canonical `WP_Post` objects after raw normalization. This
executes no SQL: core's intervening `get_post()` normalization becomes an
identity operation, while the normal `WP_Query` lifecycle remains the sole
owner of post, metadata, and taxonomy cache policy.
At page sizes K=1, K=20, and K=50, it must observe
exactly three plugin-owned statements (one plan, one rank, and one hydrate),
then exactly two batched core pre-loop cache statements (one `postmeta` and one
`term_relationships`), then exactly two batched author-cache statements (one
`users` and one `usermeta`) on the first `the_post()`. The complete envelope is
therefore exactly five statements before the loop and seven through its first
result at every K. All remaining result iterations must add zero statements
while reading the canonical post cache, `get_post_meta()`, `get_the_terms()`,
and `get_userdata()` for every result; the cache-prime and author-prime count
vectors must both be `[2,2,2]`. A separate 20-result case with
`update_post_meta_cache` and `update_post_term_cache` disabled must retain the
three plugin statements, execute zero core metadata/taxonomy prime statements,
and still serve every canonical `get_post()` read without SQL. This is the
release proof that result count cannot create an N+1 query path. A source-order
contract additionally verifies that supported core keeps the final
`get_post()` normalization between `posts_pre_query` and
`update_post_caches()` in `WP_Query::get_posts()` and performs
`update_post_author_caches()` in `WP_Query::the_post()`.

The explicit `cache_results=false` branch is also measured cold at K=50. It
must still execute only plan+rank+hydrate, perform zero canonical `wp_posts`
reads, return fifty raw-normalized post objects, and leave all fifty IDs absent
from the `posts` object cache. Re-normalizing those returned objects must add
zero statements. Thus the bounded takeover preserves WordPress's cache opt-out
instead of hiding its own unconditional cache write or paying one post query
per result.

A separate stock-unscoped lifecycle case runs a real main `WP_Query` whose
original arguments omit both `post_type` and `post_status`. It must retain the
FTS candidate marker, return the exact 20-row oracle order, execute no more
than the three plugin plan/rank/hydrate statements, execute zero core cache or
canonical-post statements with cache flags disabled, and execute zero core
`wp_posts ... LIKE` statements. This prevents an explicitly scoped test from
hiding a broken ordinary WordPress search takeover.

That seven-statement envelope covers plugin-owned search, the stock WordPress
cache lifecycle, and the ordinary template reads named above. WordPress allows
arbitrary third-party hooks and templates to issue their own SQL, so this plugin
cannot promise a total request ceiling for that unrelated application code.
The controlled acceptance lane classifies any extra core or unowned statement
as `other` and fails; deployments with custom result-loop callbacks must profile
those callbacks independently rather than attribute their queries to FTS.

A slow independent PHP oracle computes every matching row on a 2,000-post slice,
then traverses production search to the terminal cursor and compares the complete
ordered membership and integer scores. The large corpus also has
construction-known membership and ranking sentinels; merely finding one result
does not pass. Public PHP, front-end, REST, authenticated admin/Sandbox, AJAX,
and WP-CLI results are compared to a direct set-oriented `WP_FTS_Searcher`
oracle, never to a second call through the same `WP_FTS_Plugin::search()`
adapter being tested. REST and WP-CLI additionally retain exact ordered IDs and
score signatures.

Pack-cardinality evidence changes the real analyzer option, clears request
caches, measures one active distributable pack, restores the complete active
pack set, and measures again. Each side must execute exactly one plan, one rank,
and one hydrate statement and reproduce the independent oracle's exact IDs and
scores. Merely asserting that pack count should not affect SQL, or measuring
only the all-pack side, does not pass.

### Prefix representation and unavoidable broad-OR work

`kind=0` stores analyzed lexical identities. `kind=1` stores one normalized,
complete source surface per distinct surface/document, truncated only at the
252-byte identity boundary on a valid UTF-8 boundary. It does **not** store
every proper prefix. Prefix lookup is one bytewise lower/upper range on the
same unique `(lang,kind,term)` index. Matching surfaces remain alternatives:
ranking applies each surface's own `doc_freq`/IDF and takes one `MAX` for the
source-word group, so overlapping completions cannot add score or duplicate a
document. Prefix ownership is bound to the final source token; if that token is
filtered from exact analysis, prefix matching is disabled instead of falling
back to an earlier token.

This removes explosive SQL text, query counts, PHP completion arrays, and
candidate×dictionary products. It does not make exact broad OR independent of
the number of matching postings. A broad OR or single broad prefix still has
to scan and group its matching surface postings to return the exact top page.
That work occurs in one indexed ranking statement and PHP remains page-sized,
but database work is proportional to matching postings. This is the explicit
small/medium-site tradeoff; sites whose broad exact OR workload no longer fits
it should use a dedicated search service rather than expect a relational plan
to skip rows it must score.

For a multi-group `AND` whose cheapest group is exact and whose final group is
a prefix, ranking chooses between two bounded drivers without another query.
The plan already has the prefix posting sum `P` and an upper bound `A` for the
exact anchor's document frequency. If `P <= A × 8,192`, the rank statement
streams the indexed surface range and intersects the exact candidates. If the
range is larger, it scans each exact candidate's hard-capped 8,192-posting
envelope through `post_term` and classifies those term IDs through the
dictionary primary key. The comparison is multiplication-free in PHP, so even
saturated integer costs cannot wrap. Both paths preserve exact membership and
per-surface scoring in one rank statement; neither can create work proportional
to both the entire prefix range and every candidate posting envelope.

## Production data invariants

The completed schema has exactly one production source of truth for each
concern:

- `fts_terms`: dictionary and global document frequency;
- `fts_postings`: one `(term_id, post_id)` posting with a precomputed impact;
- `fts_documents`: source hash and bounded result/snippet material;
- `fts_work`: post generations, claims, capped retry state, and scope work.

The current schema has no production document-length, collection-statistics,
duplicate scalar/JSON metadata, tombstone, or parallel retrieval table.
`(term_id, post_id)` is unique, post-first candidate
probes are indexed, normalized term ranges are indexed, document frequency
matches distinct live postings, and the last durable work record is never
removed before its desired generation commits.

The post-first key is exactly `post_term(post_id, term_id)`. It intentionally
does not duplicate mutable `impact`: replacement can change a score without
rewriting that secondary key, while rank probes fetch the score from the
clustered term-first row. This keeps rebuild write amplification and storage
bounded at the cost of one clustered lookup for post-first scoring probes.

The proof compares every column and named index, including order and uniqueness,
against the production DDL. `fts_terms` has exactly `term_id`, `lang`, `kind`,
`term`, and `doc_freq`; `fts_postings` has exactly `term_id`,
`post_id`, and `impact`; `fts_documents` has exactly `post_id`, `primary_lang`,
`content_hash`, `snippet_text`, and `indexed_at`; and `fts_work` has exactly the
eighteen declared queue, lease, scope, payload, and error fields. The term
identity and term-first posting paths must be unique, and the post-first,
dictionary range, ready-work, lease, scope, and dirty paths must match their
declared key order. A leftover `term_hash` column/index, scalar `doc_len`,
duplicate index, extra queue field, or other parallel source of truth fails the
fresh schema proof.
The recoverable-work path is the exact non-unique index
`recoverable(kind,state,claim_expires_at,available_at,post_id,job_key)`; omitting
it or changing its column order fails both fresh-schema and post-reset proof.
Every MySQL/MariaDB FTS table must also report `InnoDB`; matching columns and
indexes on a non-transactional engine fail verification and are rebuilt during
dedicated schema maintenance. This engine requirement is what makes document,
posting, work-generation, and cursor-epoch publication one atomic boundary.

## Operator and maintenance invariants

Maintenance must remain bounded even when the stored debt is deliberately one
row/page beyond a hard limit. Easy one-row happy paths are not acceptance.

1. Schema repair, site provisioning, optimize, reset, reindex workers, and
   scheduled maintenance share one writer lease. A contended repair reports
   `skipped_locked` and performs **0 SQL statements**, including physical schema
   probes and DDL.
2. Document frequency is a transactional writer invariant. With **1,001**
   empty terms and a deliberately stale non-empty frequency, one optimize pass
   runs exactly **1 bounded indexed cleanup data statement**, removes exactly
   **1,000** empty terms, leaves one for a later pass, leaves the non-empty
   frequency untouched, and executes no transaction wrapper, cursor-epoch
   write, vocabulary-wide `UPDATE`, `COUNT(*)`, or correlated posting
   recomputation. Zero-frequency
   rows cannot affect membership, score, or cursor traversal, so wrapping that
   bounded physical cleanup would add three statements without adding an
   observable consistency boundary. Shared writer-lease acquisition/release is
   measured separately from that single data statement.
   Replacement cost includes rows already stored, not only the newly analyzed
   map. With **7** existing documents carrying **8,192 disjoint postings
   each**—4,096 lexical and 4,096 surface rows—there are **57,344** existing rows.
   One post-first covering-index query scans at most **50,001** rows inside a
   derived table and returns at most seven per-post aggregates. A separate
   100,000-posting lower-key decoy forces the measured `existing_posting` access to
   be a covering `range` on `post_term`, rather than letting a whole-index
   scan look cheap. Performance Schema may account for both inner and outer
   query blocks, but must report at most **100,008 rows examined**, seven rows
   sent, zero disk temporary tables, zero sort-merge passes, and at most five
   seconds of server time. The isolated 50,001-row inner block may use neither
   filesort nor a temporary table.

   A full deletion pass admits six complete documents (**49,152** posting
   mutations) and defers the rest before `BEGIN`; the terminal pass admits the
   remaining document (**8,192**). The measured plan is consumed by the transaction
   without a second frontier read, and a forged or mismatched plan is rejected
   before `BEGIN`. Draining the deletion fixture takes exactly **2** passes and
   leaves zero target postings, documents, and dictionary terms while
   preserving the exact 100,000-posting decoy.

   A separate survivor fixture has six target and six external documents
   sharing **49,152** terms (**98,304** postings, initial `doc_freq=2`). One
   production pass must change all 49,152 frequencies to one, remove only the
   target postings/documents, and retain every external posting, term, and exact
   frequency. Its dictionary UPDATE must put the materialized
   `post_term` driver first, `STRAIGHT_JOIN` the dictionary through
   `PRIMARY`, affect exactly 49,152 terms, examine at most 250,000 server rows,
   create no disk temporary table, require at most one bounded merge pass, stay
   at most 4 KiB, and complete within five seconds.

   The combined three-target DELETE must materialize a double-derived,
   post-first driver capped at the proven maximum of **50,100** rows (50,000
   postings plus at most 100 documents with no posting), then join the posting,
   dictionary, and document targets through `PRIMARY`. This prevents MariaDB
   from driving through the dictionary-wide `empty_terms` range. MySQL and
   MariaDB `EXPLAIN FORMAT=JSON` must name `post_term`; each pass has one
   frontier statement and exactly five transaction statements at the full
   boundary: `START TRANSACTION`, post-first dictionary decrement, combined posting/dictionary/document deletion,
   epoch UPSERT, and `COMMIT`. The tagged
   DELETE appears once in that exact order in each of the 17 retirement
   transactions. A full ordinary retirement affects exactly **98,310** rows
   (49,152 postings, 49,152 terms, and six documents), examines at most 300,000
   server rows, emits no result rows, creates no disk temporary table, requires
   at most two bounded row-id merge passes, is at most 4 KiB, and completes
   within five seconds.
   Each pass takes at most five seconds and stays below the 128 MiB PHP ceiling.
3. Deactivation retains tables/data. Uninstall removes the four current tables
   with **one idempotent `DROP TABLE` statement per site** and issues exactly
   two `DROP INDEX` statements for its recorded, exactly matching core-table
   indexes; a reused unowned index or a missing/changed owned index is never
   dropped. Under the shared writer lease, uninstall first persists the exact
   non-autoloaded scalar fence `1`, then drops owned indexes and tables and
   deletes every operational option while retaining that fence. An unexpired
   owner makes that site fail hard with **0 `DROP INDEX`, 0 `DROP TABLE`, and 0
   option deletions**; an expired owner is atomically replaced before the fence,
   two owned index drops, and one table drop. The lease remains
   owned through schedule and option cleanup, and token-checked release cannot
   delete a successor. Partial DROP failure retains the fence. Preloaded schema,
   worker, foreground, and scheduler callbacks then perform **0 SQL, 0 option
   mutations, and 0 schedules**. Repeated uninstall is idempotent. Installing
   the ZIP inactive remains tableless and fenced; only explicit activation clears
   the fence under a lease, restores exactly four current tables, and queues one
   reconciliation scope. A health CAS whose expected and replacement values
   are already byte-for-byte equal is a successful matched no-op, not lost
   ownership: a fresh same-second activation must not issue five identical
   retries and fail after publishing the correct pending state. A stale
   network-activation event is rejected by its
   revoked capability before site enumeration, SQL, switching, or scheduling.
   A multisite run continues with uncontended siblings and aggregates failed blog
   IDs for an operator retry.
   A **205-site** network is discovered as three `fields=ids` pages with offsets
   **0, 100, 200** and never holds more than **100 IDs**; a second complete run
   must be idempotent.
   This is the one intentionally site-count-proportional lifecycle path. Normal
   search, foreground mutation, and index-worker invocations retain
   corpus-independent statement bounds, but network uninstall must execute one
   bounded cleanup against each separate site's physical table namespace.
   WordPress invokes plugin uninstall synchronously while the plugin code is
   still present; after deletion there is no trustworthy plugin callback left
   to run an asynchronous continuation. Therefore acceptance promises bounded
   per-site work and 100-ID discovery memory, not a site-count-independent
   finite total for the complete network. This operator-initiated deletion work
   is neither visitor/search load nor part of any normal request path.
4. Each network-schema event selects and provisions exactly **one site**. A
   provisioning failure, including a declined `switch_to_blog()`, schedules the
   **same input cursor**; only success may schedule that site's ID as the next
   keyset cursor. No event holds or mutates a multi-site schema page.
5. `wp fts reindex` accepts only scope controls; worker batch and time controls
   belong to `wp fts process-batch`. With
   **100,000** source rows available, it executes exactly **one scope UPSERT**,
   schedules exactly **one** WP-Cron event, and returns exactly seven normalized
   fields (`status`, `post_status`, `post_type`, `language`, `requested_limit`,
   `has_more`, and `message`). It performs **0 source-ID/content SELECTs, 0 post
   queue UPSERTs, 0 document analysis, and 0 writer-lease mutations**. Later
   workers use one ascending-ID keyset selector per pass. No
   selector or post queue UPSERT contains more than **100 IDs**, even when a
   manual `process-batch` request is larger, and no complete ID list exists in
   PHP. `--limit` is decremented atomically with cursor advancement. Filter
   input is rejected before `explode()`/SQL above **4,096 aggregate bytes**,
   **32 values**, or **64 bytes per value**.
6. Retrying the maximum **20** retained failure-history records uses **one
   queue UPSERT** and **one cron schedule**, not one of either per record.
7. Sandbox detail ID input is rejected before expansion above **2,048 scalar
   bytes**, **50 array items**, or **20 bytes per item**. The exact 50-ID page
   boundary remains accepted.
8. Normal status/Health trusts stored readiness and reports bounded pending
   post/scope work counts, their exact-or-lower-bound relations, and the active
   reconciliation cursor. It does not expose whole-corpus totals. A logically
   ready init/readiness/storage/search-takeover/empty-shutdown request performs
   **0 uninstall-fence probes, 0 plugin SQL, 0 option mutations, and 0
   schedules**; the direct non-autoloaded fence probe exists only at an actual
   writer, schema, foreground-persistence, or scheduling boundary.
   Normal Health/status executes **1 bounded durable-work aggregate** in the
   contract adapter. It executes no `SHOW`, `information_schema`, or
   corpus/document-table count.
   Only explicit diagnose/support-snapshot paths set
   `schema_verification=physical`; they retain the bounded queue and
   reconciliation fields. No operator status path exhaustively counts posts or
   documents. Status, diagnose, and support snapshots remain
   read-only when physical verification reports a damaged schema: they perform
   no repair, enqueue, option mutation, or queue scheduling.
   On MySQL and MariaDB, a cold explicit operator status or support snapshot is
   exactly **3 plugin statements**: one tiny
   `information_schema.STATISTICS` capability-column read, one table-bounded
   UNION snapshot covering all four FTS tables plus all three supporting core
   indexes,
   and one bounded `fts_work` status aggregate. A healthy diagnose with a
   hydrated search page is exactly **6** after adding plan/rank/hydrate; a
   nonhydrating diagnose is at most **5**. The corresponding SQLite physical
   snapshot is one portable schema statement, so operator/support totals **2**
   including work. Real cold storage-metadata evidence must reproduce exactly
   two physical statements on MySQL 8.0 and MariaDB 10.11. Returning
   to one `information_schema` probe per table/column/index is a statement-count
   regression even though this is an explicit operator path.
9. `wp fts delete <id>` refuses an eligible canonical WordPress post. A missing
   or ineligible post creates exactly **one durable post generation** and **no
   direct derived-table DELETE**; the shared worker performs and acknowledges
   the physical cleanup.
10. Reset is independent of stored row count. The MySQL and MariaDB proof
    reseeds the frontier target and retains its plan decoy, so it starts with
    **157,344** populated postings, **57,345 terms**, **7 documents**, the
    cursor epoch, and one non-epoch durable work row. The storage-only proof
    executes exactly **9** database statements: one primary-key epoch read, one stale-generation DROP,
    four `CREATE TABLE ... LIKE` statements, one epoch-and-random-incarnation
    seed, one atomic
    eight-pair `RENAME TABLE`, and one retired-generation DROP. Those statements
    contain no `DELETE` or `COUNT`, perform no corpus read, and are each at most
    4 KiB. Reset finishes within **5 seconds**, allocates at most **16 MiB**,
    and stays below **128 MiB** absolute PHP memory and Linux `VmHWM`. The
    published tables are empty except for the incremented epoch row. Its old
    and new incarnations are valid lowercase 32-hex values and differ; complete
    columns, named indexes, and `InnoDB` engines still match the current schema,
    and no staging or retired table remains.
    SQLite's same **409,600-posting** fixture uses exactly **21** bounded schema/
    transaction statements with the same no-DELETE/no-COUNT rule. Takeover is
    pending before the first DDL. Ownership is rechecked immediately before the
    atomic rename and retired DROP. A forced post-publication DROP failure
    reports failure, retains pending takeover and four deterministic retired
    tables, and a retry cleans those fixed names without a row-delete fallback.
    Only after all storage writes succeed, the plugin adds exactly **one**
    unfiltered complete-corpus scope UPSERT and **one** bounded schedule attempt.
    The complete plugin path therefore has exactly **10 plugin-owned
    storage/queue statements**—one epoch read plus **9 writes**—with WordPress's
    schedule-option machinery measured separately. The response reports
    `reset_strategy`, `reconciliation_queued=true`, and normal status fields,
    with no removed-row or queue counters. That exact scope is sufficient to
    restore readiness after bounded discovery and physical verification.
    Publication, retired-DROP, scope-UPSERT, and schedule failures remain
    pending and do not emit reset success. Operators must not queue a second filtered CLI reindex;
    `process-batch` only advances the automatic scope when a manual pass is
    needed.

Validation runs the named boundary cases, not substitutes with smaller data.
The SCRIPT/STYLE gate ignores 20,001 tag-looking payload strings in each opaque
element while counting every real opener and closer, rejects real token/depth
max+1, and preserves both escaped/double-escaped SCRIPT boundaries with 20,001
hidden words. The lazy-lemma race gate binds reads to the attested runtime and
sidecar descriptors plus the authenticated block digest: an atomic rename keeps
the manifested generation, a same-inode mutation rejects, and restoration
cannot consume poisoned decoded bytes. Both gates run their exact 128-MiB cases
under normal PHP and `php -n`.

```sh
WP_FTS_MIN_CHECKS=1 WP_FTS_TEST_FILTER='schema repair performs zero' php indexer/tests/run.php
WP_FTS_MIN_CHECKS=1 WP_FTS_TEST_FILTER='optimize' php indexer/tests/run.php
WP_FTS_MIN_CHECKS=1 WP_FTS_TEST_FILTER='existing-posting prefix' php indexer/tests/run.php
WP_FTS_MIN_CHECKS=1 WP_FTS_TEST_FILTER='relational replacement plans are opaque' php indexer/tests/run.php
WP_FTS_MIN_CHECKS=1 WP_FTS_TEST_FILTER='100000-post source shape' php indexer/tests/run.php
WP_FTS_MIN_CHECKS=1 WP_FTS_TEST_FILTER='uninstall' php indexer/tests/run.php
WP_FTS_MIN_CHECKS=1 WP_FTS_TEST_FILTER='ready no-op request' php indexer/tests/run.php
WP_FTS_MIN_CHECKS=1 WP_FTS_TEST_FILTER='preloaded schema repair' php indexer/tests/run.php
WP_FTS_MIN_CHECKS=1 WP_FTS_TEST_FILTER='preloaded network activation job' php indexer/tests/run.php
WP_FTS_MIN_CHECKS=1 WP_FTS_TEST_FILTER='failed network schema provisioning' php indexer/tests/run.php
WP_FTS_MIN_CHECKS=1 WP_FTS_TEST_FILTER='failed network blog switch' php indexer/tests/run.php
WP_FTS_MIN_CHECKS=1 WP_FTS_TEST_FILTER='bulk failed-item retry' php indexer/tests/run.php
WP_FTS_MIN_CHECKS=1 WP_FTS_TEST_FILTER='admin detail ID parsing' php indexer/tests/run.php
WP_FTS_MIN_CHECKS=1 WP_FTS_TEST_FILTER='rejects oversized filters' php indexer/tests/run.php
WP_FTS_MIN_CHECKS=1 WP_FTS_TEST_FILTER='scheduled indexing cron expands only one bounded scope page' php indexer/tests/run.php
WP_FTS_MIN_CHECKS=30 WP_FTS_FAIL_ON_PENDING=1 WP_FTS_TEST_FILTER='maximum mixed worker composition stays inside' php indexer/tests/run.php
WP_FTS_MIN_CHECKS=22 WP_FTS_FAIL_ON_PENDING=1 WP_FTS_TEST_FILTER='content failure settles before maximum writer' php indexer/tests/run.php
WP_FTS_MIN_CHECKS=118 WP_FTS_FAIL_ON_PENDING=1 WP_FTS_TEST_FILTER='late worker commit failure stays recoverable' php indexer/tests/run.php
WP_FTS_MIN_CHECKS=12 WP_FTS_FAIL_ON_PENDING=1 WP_FTS_TEST_FILTER='SQLite maximum prepared identity document is a permanent pre-SQL rejection' php indexer/tests/run.php
WP_FTS_MIN_CHECKS=10 WP_FTS_FAIL_ON_PENDING=1 WP_FTS_TEST_FILTER='SQLite largest maximum-width transport boundary uses one dictionary write and one resolver' php indexer/tests/run.php
WP_FTS_MIN_CHECKS=16 WP_FTS_FAIL_ON_PENDING=1 WP_FTS_TEST_FILTER='SQLite aggregate transport splits once and preflights 100 documents linearly under 128 MiB' php indexer/tests/run.php
WP_FTS_MIN_CHECKS=1 WP_FTS_TEST_FILTER='wp-cli delete routes' php indexer/tests/run.php
WP_FTS_MIN_CHECKS=1 WP_FTS_TEST_FILTER='SQLite reset rebuilds 409600' php indexer/tests/run.php
WP_FTS_MIN_CHECKS=1 WP_FTS_TEST_FILTER='MySQL reset revalidates' php indexer/tests/run.php
WP_FTS_MIN_CHECKS=1 WP_FTS_TEST_FILTER='post-publication cleanup failure' php indexer/tests/run.php
WP_FTS_MIN_CHECKS=1 WP_FTS_TEST_FILTER='atomic publication fails' php indexer/tests/run.php
WP_FTS_MIN_CHECKS=1 WP_FTS_TEST_FILTER='automatic reconciliation cannot' php indexer/tests/run.php
WP_FTS_MIN_CHECKS=1 WP_FTS_TEST_FILTER='automatic reset reconciliation completes' php indexer/tests/run.php
WP_FTS_MIN_CHECKS=1 WP_FTS_TEST_FILTER='encoded metadata extraction' php indexer/tests/run.php
WP_FTS_MIN_CHECKS=1 WP_FTS_TEST_FILTER='streamed HTML metadata' php indexer/tests/run.php
WP_FTS_MIN_CHECKS=12 WP_FTS_FAIL_ON_PENDING=1 WP_FTS_TEST_FILTER='HTML raw-text contents cannot invent markup depth or tokens' php indexer/tests/run.php
WP_FTS_MIN_CHECKS=1 WP_FTS_TEST_FILTER='lemma runtime lines' php indexer/tests/run.php
WP_FTS_MIN_CHECKS=60 WP_FTS_FAIL_ON_PENDING=1 WP_FTS_TEST_FILTER='lazy lemma block reads stay bound to the attested file generation' php indexer/tests/run.php
WP_FTS_MIN_CHECKS=1 WP_FTS_TEST_FILTER='compressed lemma pack importer emits' php indexer/tests/run.php
WP_FTS_MIN_CHECKS=1 WP_FTS_TEST_FILTER='64-file lemma packs' php indexer/tests/run.php
WP_FTS_MIN_CHECKS=1 WP_FTS_TEST_FILTER='bundled multi-file lemma pack' php indexer/tests/run.php
WP_FTS_MIN_CHECKS=1 WP_FTS_TEST_FILTER='lemma source importers' php indexer/tests/run.php
WP_FTS_MIN_CHECKS=1 WP_FTS_TEST_FILTER='top-language pack audit' php indexer/tests/run.php
WP_FTS_MIN_CHECKS=1 WP_FTS_TEST_FILTER='PoliMorf external pack workflow' php indexer/tests/run.php
WP_FTS_MIN_CHECKS=1 WP_FTS_TEST_FILTER='Jieba dictionary giant line' php indexer/tests/run.php
WP_FTS_MIN_CHECKS=1 WP_FTS_TEST_FILTER='Jieba 32-prefix fanout' php indexer/tests/run.php
```

The exact 100-row scope page is the unit worker boundary. The isolated
real-database lane additionally exercises the **1,000/1,001** enqueue boundary,
and release acceptance still requires the 50k/100k database lanes below.
Lifecycle evidence must verify physical table absence plus the exact retained
fence after uninstall, inactivity after reinstall, and four-table restoration
only after explicit activation.

## Deterministic corpus

`tests/integration/relational-fts-worst-case.php` creates real WordPress posts
and indexes them through the production WP-CLI/worker path. It supports:

| Profile | Documents | Use |
| --- | ---: | --- |
| `2k` | 2,000 | local small-site oracle and failure smoke |
| `50k` | 50,000 | required pull-request MariaDB/MySQL evidence |
| `100k` | 100,000 | required pull-request boundary/release MariaDB/MySQL evidence |

Only these clean profile/engine tuples are acceptance lanes, with stable lane
identities: `50k/mariadb-10.11` (`mariadb1011-50k`),
`50k/mysql-8.0` (`mysql80-50k`),
`100k/mariadb-10.11` (`mariadb1011-100k`), and `100k/mysql-8.0`
(`mysql80-100k`). Every other tuple is rejected before Docker starts unless
`--allow-dirty` explicitly marks it diagnostic. A clean report must carry the
exact expected lane ID; success from a cheaper arbitrary profile/engine
combination cannot substitute for one of these four lanes.

The 100k pull-request job is one two-engine matrix. MariaDB 10.11 and MySQL 8.0
therefore execute the identical structural, performance, memory, concurrency,
schema-repair, report validation, finalization, and failure-artifact requirements;
neither engine has a reduced boundary path.

The 2,000-document profile is the explicit local small-site validation profile,
not a clean acceptance lane or a lower production-size boundary. The 50k/100k
lanes prove that the same bounded query shapes hold as a medium site grows.

The corpus uses titles, excerpts, HTML bodies, language spans, several post
statuses, password-protected posts, and deterministic 80–5,200-token lengths.
It includes these adversarial families at 100k, scaled down without changing
their shape in smaller profiles:

- common OR terms in 100k, 95k, and 85k documents (280k posting hits);
- a 64-document rare anchor combined with two >90k common groups;
- 20,000 distinct `kind=1` final-word surface completions and 100k posting hits;
- a rare-anchor `AND` whose final mandatory group is that full 20,000-term
  surface range, proving completion vocabulary cannot create a candidate×range
  cross product or defeat rare-group anchoring;
- a corpus-wide exact group combined with a one-document final prefix, proving
  the prefix becomes the AND anchor and the common posting range is not scanned;
- all distributable analyzer packs active without query-plan fanout;
- 5,000 higher-impact hidden rows and 20,000 higher-impact dirty rows ahead of
  clean public results;
- an absent mandatory group, ambiguous morphology, field-impact sentinels, and
  100 pages of cursor traversal.

The 1,024-word explosive input is a separate containment probe. It must
be rejected before FTS SQL with a typed error and no silent provider switch.
A fresh isolated 128 MiB process exercises every exact public boundary through
the production analyzer, indexer, relational storage, searcher, and queue:

- a 4,095-byte contiguous CJK run is accepted, produces four distinct terms and
  5,454 bounded occurrences, and is written through the real relational writer;
  the next complete CJK code point produces 4,098 bytes and must raise the typed
  4-KiB lexical-run error before SQL;
- an infinite custom tokenizer is stopped after consuming exactly thirteen
  occurrences (twelve accepted alternatives plus the rejecting occurrence),
  before storage SQL;
- twelve logical groups and twelve aggregate alternatives are accepted with one
  dictionary-planning statement, while the thirteenth of either is rejected
  before SQL;
- HTML preflight accepts exactly 20,000 markup tokens, 256 nested elements, a
  16,384-byte tag, 128 attributes, a 4,096-byte ordinary attribute, a 64-byte
  `lang`/`xml:lang` value, and eight language subtags; the next unit at every
  boundary raises its specific typed error before either WordPress HTML
  processor implementation, a fallback parser, or storage SQL is entered;
- a custom HTML processor may emit exactly 40,001 syntax/text tokens and 2 MiB
  of aggregate text/tag/language output, with a 256-row active element stack,
  16-KiB tag, and 64-byte language limits. Token-type names accept 64 bytes and
  have a separate 2-MiB aggregate envelope. WordPress 6.6 processor events push
  and pop scalar state rows without requesting breadcrumbs; processors without
  that event contract use the fallback parser. Inline paths use persistent
  scalar node IDs rather than one ancestor array per text run. Token 40,002,
  output unit 2,097,153, tag byte 16,385, language byte 65, token-type byte 65,
  absolute provider depth 260, and an infinite provider each stop on the first
  excess unit before trim, uppercase, coalescing, Unicode, or SQL;
- two fresh 128-MiB processes combine the exact 20,000-markup-token and
  256-element-depth boundaries in valid 89,490-byte and 99,235-byte documents.
  They must preserve zero and 9,745 occurrences respectively, complete within
  two seconds, add at most 16 MiB PHP allocation, and remain below 128 MiB
  PHP/RSS. This locks the invariant that retained inline ancestry is
  O(markup tokens + depth), never O(text runs * depth);
- split inline lexical runs accept and preserve exactly 4,096 bytes. A fresh
  128-MiB worst-case process then retains 20,000 one-byte custom-processor text
  segments and must reject the combined run with `lexical_run_bytes` before
  concatenation exceeds 4 KiB, within two seconds and the same 16-MiB-delta /
  128-MiB-absolute memory gates;
- custom CJK-tokenizer, token-normalizer, and stemmer scalars accept exactly
  4,096 bytes and reject byte 4,097 before trim, Unicode normalization, or
  character-length work. Configured analyzer arrays accept exactly 20,000 rows and
  reject row 20,001 before `array_values()`; term, language, surface, and
  position fields independently accept their exact storage boundary and reject
  the first excess byte before casts or normalization;
- a fresh 128 MiB process feeds 100,000 nested `<span>` elements and a
  1,800,000-byte `lang` value through the production analyzer and relational
  indexer. Both must reject before SQL in at most one second, add at most
  16 MiB PHP allocation, and remain below 128 MiB absolute RSS;
- a separate fresh 128 MiB process feeds a valid 1,500,000-byte field containing
  250,000 encoded one-character words through production metadata extraction.
  It must stream the optional HTML sidecar, reach the typed 20,000-occurrence
  rejection in at most two seconds, add at most 16 MiB PHP allocation, and stay
  below 128 MiB absolute RSS. A companion equivalence gate retains every byte
  below and at the exact 20,000-byte sidecar boundary and proves a smaller
  presentation cap cannot change term frequencies, the content hash, other
  field metadata, or later required metadata;
- gzip lemma runtime rows accept exactly 4,096 bytes and reject byte 4,097 with
  one typed reason. Validation and lookup-index construction enforce the same
  decoded-line boundary. A fresh 128 MiB process then creates a valid, sorted
  gzip shard containing exactly
  8,388,608 decoded bytes and 32,768 rows. Without a sidecar it must reject at
  pack construction before any document/query token lookup, complete-runtime
  digest, or indexed payload read. The indexed equivalent auto-shards into two
  runtime files with 512 exact 16-KiB-or-smaller blocks. Its exact
  4,096-distinct-term maximum document must retain all 4,096 morphologies while
  opening each file once and loading exactly 64 blocks / 1 MiB decoded; its
  twelve-group adversarial query opens the same two files once and loads exactly
  twelve blocks / 192 KiB decoded. The two runtimes and two sidecars are hashed
  exactly once before first payload use. Measured compressed payload work is
  24,163 document bytes and 4,530 query bytes; complete attestation is 253,603
  bytes. Rejection must finish within one second;
  the complete indexed proof within five seconds; both fresh children must stay
  below 128 MiB PHP/RSS. A separate fresh 128 MiB process supplies a compressed
  shard expanding beyond 180 MiB without a sidecar. Construction must reject
  structurally with zero payload hashes or reads, before expansion can approach
  the process memory limit;
- two fresh 128 MiB processes send one 32-MiB unterminated plain or gzip line
  through each of the normalized-TSV, CoNLL-U, UniMorph, and PoliMorf source
  readers. All eight paths must reject at the shared 64-KiB source-line boundary
  before publishing a manifest, the complete four-importer child within ten
  seconds and 32 MiB PHP peak. Separate fresh children import 17,000 unique
  maximum-width namespaced pairs (251-byte `qaa` terms and 252-byte `pl` terms)
  through the generic and PoliMorf importers with both row settings left at
  200,000. Each must retain every row, flush exactly two lexical-byte chunks,
  retain at most 8,388,608 lexical bytes per dedupe map, and auto-shard before
  either a 256-block or 64-KiB header boundary. The measured packs use six
  runtime files and 532 blocks, with at most 98 blocks per file and at most
  16,128 / 16,192 decoded bytes in one block; combined encoded-runtime plus
  lookup evidence is 441,557 / 442,622 bytes. Each must complete within fifteen
  seconds and stay below 128 MiB PHP/RSS. Two more fresh children force 15,000
  reverse-sorted one-row chunks. Both must preserve the exact sorted digest and
  first/middle/last lookup, merge through at least two levels with fan-in at
  most 64 and at most 192 live temporary files, and complete within ten seconds and
  128 MiB PHP/RSS. High-entropy 300,000-row children (39.6 / 41.4 MB sources) must reject at
  the 16-MiB physical runtime-plus-lookup boundary before a manifest, remove all
  partial output, and complete within fifteen seconds and 128 MiB PHP/RSS. Every child
  records its loaded ini path, and a parent launched with `php -n` must keep all
  descendants on `php -n`; none may silently reload shared extensions;
- fresh normal and `php -n` children drive all four source importers through
  every original-input boundary. A 65,536-byte line, 64-MiB physical artifact,
  512-MiB decoded gzip stream, and 8,000,000-line source each produce an
  activatable one-row pack; the first byte or line above each boundary rejects
  with no manifest or partial output. The four-importer proofs have 32-MiB PHP
  ceilings and one-CPU time ceilings of 5 / 10 / 10 / 90 seconds respectively.
  A stale physical preflight followed by growth to 67,108,865 bytes or a
  same-size inode replacement rejects before hashing source bytes. PoliMorf
  NOTICE retention accepts exactly 64 metadata lines / 65,536 bytes and rejects
  the next line or byte before append. Root output symlinks and source/output
  overlap are refused by all four importers and the external builder without
  changing the target; injected directory symlinks inside owned temporary trees
  are unlinked rather than followed, while caller-owned parent sentinels remain
  byte-identical;
- fresh 128 MiB CoNLL-U and UniMorph wrapper processes prove recursive source
  discovery at exactly 256 accepted files, 8,192 relative-path bytes, and
  eight directory levels; file/path/depth max+1 and in-root symlinks to outside
  bytes reject before staging or hashing. Both wrappers discard a first
  252-byte `qaa` token before staging, accept exactly 1,250,000 staged rows and
  67,108,864 staged bytes, reject the next row and byte, and remove all staging
  output. The 64-MiB byte cases complete below one second; the row-boundary
  exact/max+1 cases run under a 90-second one-CPU ceiling with at most 128 MiB
  PHP/RSS. CoNLL-U manifests, source locks, and notices must attest the original
  per-file path, digest, byte count, and ten-column model rather than the
  temporary normalized TSV;
- fresh normal and `php -n` 128 MiB audit processes classify a sparse 140-MiB
  explicit manifest as `invalid_pack` with a normal exit and 16 MiB PHP peak.
  Recursive pack-root discovery accepts exactly 256 manifests, 4,096 entries,
  262,144 aggregate path bytes, and depth eight; each max+1 rejects, while a
  symlinked manifest and a `root`/`root2` prefix escape discover zero packs;
- fresh normal and no-extension 128 MiB processes exercise the full 64-shard
  manifest limit. Missing, overlapping, out-of-order, and unnormalized ranges
  must reject during manifest validation before runtime path resolution. A
  valid indexed 64-shard pack must return first, middle, and last terms while
  opening exactly one file and decoding no more than one bounded block per term;
  a gap miss opens zero files. The complete proof must finish within two seconds, add
  at most 32 MiB PHP allocation, and stay below 128 MiB PHP/RSS;
- a fresh 128 MiB production process configures two independent 64-file packs,
  reaching the exact 128-file analyzer aggregate. Thirty-two language-scoped
  HTML fields retain 4,096 morphologies while opening each selected file once,
  reading 4,096 blocks, 1,548,588 compressed bytes, and exactly 64 MiB decoded.
  Current-second integrity work hashes 256 runtime/sidecar files and 2,056,748
  physical bytes once. The complete proof must finish within ten seconds and
  stay below 128 MiB PHP/RSS. Two distinct physical copies are
  accepted; a third declares 192 files and must raise `configured_pack_metadata`
  before opening any lookup header or payload, in normal PHP and `php -n`;
- runtime and sidecar attestation must not trust a pre-read `stat()`: a
  deterministic stream that reports one byte and then grows stops after reading byte
  16,777,217 with `runtime_lookup_bytes`, within two seconds and 128 MiB. A
  same-size in-place current-second replacement
  with identical coarse `dev`/`ino`/size/mtime/ctime is rehashed and rejected in
  the next lookup batch without sleeping; one batch still hashes each selected
  file only once. Both gates run under normal PHP and `php -n`;
- one document with exactly 4,096 distinct terms is written with all 4,096
  terms and postings, while 4,097 terms are rejected before SQL;
- eighty distinct 252-byte source surfaces create exactly eighty `kind=1`
  identities, and an 81st creates one additional identity; 20,000 repeated
  occurrences of one surface still create one identity rather than 20,000
  proper-prefix rows;
- one maximum document accepts 4,096 lexical plus 4,096 distinct surface rows
  (8,192 postings total). A 4,097th surface or lexical identity is rejected by
  typed preflight before SQL. Over-width raw surfaces retain the exact longest
  valid UTF-8 prefix within 252 bytes even when no exact lexical identity fits;
- a 1,000-ID enqueue is one `fts_work` UPSERT no larger than 1 MiB; 1,001 IDs
  are rejected before SQL.

Focused real-SQL regressions additionally add 4,096 unrelated dictionary
identities beside one requested identity and require exact planning to return
one row through the unique full-identity index. They delete a cursor's exact
identity while advancing its epoch and require one plan statement and zero rank
statements, with analyzer-empty cursors rejected at zero SQL. A forward and
reverse pagination fixture makes two oversized canonical rows fill the complete
`K+1` window, then proves the signed progress cursor reaches the next returnable
row without repeating that empty window.

Every required MariaDB and MySQL lane repeats that identity-byte proof against
the populated corpus and retains the tagged plan, Performance Schema event, and
JSON `EXPLAIN`: 4,096 unrelated identities must still send exactly one exact
planning row, use `term_identity`, and preserve the reference result and score
signature. The lane then deletes the requested identity and advances the epoch
in one writer-locked transaction; replaying its cursor must issue exactly one
plan statement, zero rank/hydrate statements, and raise a cursor error.
Finally it converts the real `fts_work` table to MyISAM, requires verification
to report that exact engine mismatch, and runs production `create_tables()`
under the shared writer lock. Repair must drop the invalid current table exactly
once and restore the exact current InnoDB schema. Production does not preserve or
copy rows from a physically invalid work table. A separate Plugin-level proof
requires schema repair to replace that unknowable state with exactly one
bounded `global-corpus` reconciliation scope carrying reason `schema_repair`.
Only after those product observations does the disposable harness restore its
pre-fixture work rows from a temporary backup so later measurements remain
independent. That restoration is explicitly labeled fixture cleanup, not
product recovery; partial cleanup invalidates the lane.

The infinite source makes an external 180-second `SIGKILL` timeout mandatory,
not an ordinary test-framework timeout. The process records the actual wpdb
statements for every path, requires zero statements across all rejecting paths,
removes and verifies every fixture row, and retains PHP peak allocation plus
Linux `VmHWM`/`VmRSS`; each must remain within 128 MiB.

Analyzer-pack validation and indexed runtime lookup
enforce the same maximum of twelve lemmas for one surface, including candidates
split across shards. Twelve
must round-trip. The streamed source compiler must turn thirteen or more source
lemmas into one explicit surface-to-itself ambiguity no-op and publish the
source-pair count; it must never retain a lexical first-twelve subset. A raw or
corrupt runtime containing candidate thirteen must fail validation and lookup
rather than disappear or create a thirteenth query alternative. Optional Jieba
segmentation uses only the pinned runtime. It preserves exact dictionary
matching for wide runs, reads attested indexed ranges, and never scans the
complete dictionary during an analyzer invocation.
The pinned source digest and size must match the
[Jieba runtime manifest](../../components/full-text-search/resources/runtime/jieba/manifest.json).

Synthetic dictionaries are not sufficient for the release boundary. CI
initializes and attests the gitlink, upstream URL, dictionary, and MIT license
against the identities in the Jieba runtime manifest. The worst-case runner
repeats that operation inside the detached current package worktree at
`components/full-text-search/resources/sources/jieba`. A source-bound
128 MiB process then analyzes **256 punctuation-separated, distinct CJK runs**
both cold and after saturating the high-fanout cache. Each complete analysis
must take less than five seconds, perform zero complete dictionary scans and
at most 256 indexed range reads, perform zero complete source-hash scans per
pinned construction, add at most 24 MiB PHP peak and RSS, and remain below
128 MiB RSS. A separate anti-rescan pass repeats
one 17-prefix CJK run **300 times**, cold and after high-fanout saturation. Each
pass emits exactly 18,600 terms, reads at most 20 indexed source ranges total,
performs no complete dictionary scan, finishes within two seconds, adds at most
24 MiB PHP peak and RSS, and remains below 128 MiB RSS. Whole-run memoization
cannot hide prefix-work growth: 300 distinct permutations of those 17 prefixes
must meet the same term, range-read, scan, time, PHP, and RSS ceilings. Another
300 distinct runs keep 16 prefixes hot while changing the seventeenth; they
must emit 18,600 terms, read at most 350 indexed ranges, finish within two
seconds, perform no full scan, add at most 24 MiB PHP peak, and remain below
128 MiB RSS. The disjoint case uses 300 different 17-prefix sets. It must emit
18,600 terms, read at most 4,000 populated ranges rather than rescan the source,
finish within three seconds, perform no full scan, add at most 40 MiB PHP peak,
and remain below 128 MiB RSS.

The maximum accepted pinned-source fanout is one 4,095-byte run with exactly
1,365 prefixes, 285,075 eligible dictionary rows, and 2,581,996 candidate-word
bytes. It must emit exactly 5,454 terms, read at most 1,600 indexed ranges,
finish within five seconds, perform no full scan, add at most 64 MiB PHP peak,
and remain below 128 MiB RSS. The complete-cache proof then covers every
LanguagePipeline-reachable pinned Han prefix: exactly 5,628 prefixes, 337,399
candidate rows, and 3,013,489 candidate-word bytes, while also accounting for
the source's 5,652 prefixes, 337,461 eligible rows, and 3,013,799 candidate
bytes before the public Han filter. Five accepted lexical runs must populate
that complete cache with exactly 5,632 indexed range reads, no full scan and no
eviction, in under five seconds, with at most 64 MiB PHP peak and less than
128 MiB RSS. A final pass exercises the exact
20,000-occurrence boundary with 20,000 distinct one-character CJK runs; it must
finish within 15 seconds under a 24 MiB PHP/RSS delta, remain below 128 MiB RSS,
and perform zero dictionary scans and zero indexed range reads. The committed
lookup is deterministically rebuilt and byte-compared from the pinned source
under a 128 MiB/60-second process ceiling. Its size, digest, and source-range
count must match the Jieba runtime manifest. The production ZIP must contain
exactly that lookup, the pinned dictionary, and its license under the curated
runtime path; it must contain no raw Jieba checkout. A fresh process extracted
from the ZIP must make
`from_pack_option(true, 'zh')` select that runtime source and lookup with zero
full-source hash scans before real segmentation succeeds.

The complete indexing fanout above is not allowed to leak into visitor query
work. A separate fresh-process proof sends a 4,095-byte,
1,365-distinct-Han query through the public set-oriented `Searcher`. The
twelve-alternative plan allowance is passed into every configured CJK producer
as a thirteen-item observation ceiling, including the multi-language pack
dispatcher. The bundled Jieba producer must detect thirteen unique fallback
items before prefix preloading, raise the typed `analyzer occurrences` budget,
and execute exactly zero storage calls, complete dictionary scans, indexed
range reads, or candidate-cache insertions. That rejection must finish within
one second and add at most 4 MiB of PHP peak allocation. The adjacent accepted
boundary of twelve punctuation-separated one-character occurrences must still
reach storage as exactly twelve logical groups and alternatives.

Every one of those ten pinned-source workloads is also repeated in its own
fresh PHP process with `memory_limit=128M`. Each child records its absolute PHP
peak and Linux `VmHWM` and must keep both at or below 128 MiB, so a high-water
mark left by an earlier adversary cannot be mistaken for the current workload.
In each fresh child, PHP 8.1's non-resettable allocator is measured
conservatively as lifetime PHP peak after the workload minus live PHP usage
before it; Linux peak growth is `VmHWM` after minus `VmRSS` before. Thus a
transient allocation below an older high-water mark cannot disappear. The
child's input byte count, SHA-256, logical unit count, term/range inventory, and
fanout candidate inventory bind it to the parent workload. Those isolated
figures are authoritative for the 24, 40, or 64 MiB per-case limits. The parent
still records every `rss_peak_bytes` and its final PHP/`VmHWM` lifetime peak as
a cumulative multi-workload diagnostic; it is not relabeled as one request's
memory after earlier adversaries have raised that process high-water mark.

Twenty additional posts carry exactly 1.9 MB of canonical source each. Their
padding is valid ignored HTML comment content, so the analyzer sees one bounded
visible token rather than a forbidden lexical run. Indexing must accept all
twenty. `relational-fts-max-valid-setup-v3` binds that setup to the source,
package, preliminary report, and distinct Linux seed/worker process identities.
Source construction finishes in the seed process. The worker checks the exact
ID, byte-count, and source-hash handoff against the database before consuming
it, then releases that validation state before enqueueing and measuring work.
Every worker pass retains its exact statement count, ordered byte/hash/role
vectors, duration, PHP peak delta, and conservative Linux RSS peak delta. PHP's
peak counter is reset before each pass. Linux attribution remains `VmHWM` after
minus live `VmRSS` before in the fresh worker process, never high-water mark
minus an older high-water mark. The retained aggregate peak must dominate every
per-pass peak. Before the focused phase, the harness moves the retained
dirty-head backlog's availability exactly one day forward. It then exercises
the normal 100-row worker claim while the source-byte budget indexes a bounded
prefix and returns the oversized suffix for another pass. Finally, the harness
reverses that exact offset and checks that the unrelated durable row count did
not change. This isolates the measurement without adding a production-only
worker mode or draining useful backlog. The aggregate must still index all
twenty rows in at most 100 passes. Every pass executes at most 20 recognized
statements and emits
no statement above 4 MiB. Those passes finish within 30 seconds and
add at most 32 MiB PHP and RSS while remaining below 128 MiB absolute PHP and RSS.
The one enqueue is at most 1 MiB and five seconds. Fresh-process front-end
cursor traversal must then return every complete, hash-identical body without
truncation while respecting the 4-MiB hydration transport bound. Its v2
artifact retains each returned post ID, exact content byte count, and SHA-256
on all ten pages, so final validation independently reconstructs all twenty
1.9-MiB bodies rather than trusting a child summary.

## Constrained host

`tools/run-relational-fts-worst-case.sh` provisions and verifies:

- MariaDB 10.11 or MySQL 8.0: 1 CPU, 1 GiB RAM, no swap, 256 MiB InnoDB buffer pool,
  32 MiB temporary tables, 24 connections, normal fsync durability, and
  Performance Schema current/global statement history. Account, host, user,
  connection-attribute, stage, transaction, and wait histories are disabled
  because no acceptance check reads them;
- WordPress/PHP: 1 CPU, 512 MiB container, no swap, 128 MiB PHP memory, and
  Apache prefork capped at eight request workers with one to two spare children;
- a persistent database volume, never tmpfs;
- source-bound direct-install ZIP, image digests, corpus manifest hash, database
  variables, and effective cgroup limits in evidence.

Clean acceptance uses the exact pinned MariaDB 10.11, MySQL 8.0,
WordPress, and WP-CLI manifest digests declared by the runner; image overrides
are rejected before Docker starts. The runner verifies that each selected
reference is the expected reference, the expected manifest digest appears in
the local image, and the image ID is a SHA-256. The database, WordPress, and
persistent WP-CLI probe container IDs must equal the IDs obtained by inspecting
those exact digest references. It probes cgroup v1/v2 from inside those live
containers. Each probe
must report exactly one effective CPU, 1 GiB database or 512 MiB PHP memory,
and zero usable swap. Before corpus generation, the database's cumulative
cgroup peak must be at most 768 MiB, leaving at least 256 MiB of the hard limit
for the measured workload. This checkpoint occurs after database/WordPress
initialization, so it catches oversized fixed server allocations without mixing
in corpus-dependent file cache.

The `relational-fts-resources-v2` artifact retains each effective-cgroup
probe's exact nonempty `raw` string next to `raw_sha256`. Database memory
checkpoints use `relational-fts-database-cgroup-memory-v2`; WordPress memory
checkpoints use `relational-fts-wordpress-cgroup-memory-v3`. They retain the
same raw-counter binding for every checkpoint. An effective-cgroup probe is
exactly five tab-delimited fields: version, CPU quota, CPU period, memory
limit, and raw swap limit. A database memory probe is exactly six fields:
version plus usage, peak, limit-event, OOM-event, and OOM-kill counters. A
WordPress memory checkpoint is exactly those six fields plus container ID,
Docker `StartedAt`, host PID, and restart count, for ten fields total. Missing
or extra fields and non-unsigned numeric fields fail; validation does not trim
or otherwise normalize the retained payload. Every parsed field must equal its
structured evidence. For cgroup v1, the raw swap field is the combined
memory-plus-swap limit, so effective swap must equal raw swap minus memory;
cgroup v2 uses the raw swap field directly. Producer validation, memory
finalization, and final evidence validation all recompute
`SHA-256(raw) === raw_sha256`; a missing, empty, independently changed, or
structured-inconsistent raw probe fails acceptance.
`relational-fts-resource-verification-v1` remains the verification-envelope
schema because its own fields did not change.

The runner also records database usage, cumulative peak, limit events, OOM
events, and OOM kills after the isolated maximum existing-posting frontier, after
the forced rebuild, immediately before all 40 planned cold-cache database
restarts (four cases × ten samples), before the cold restart that isolates the
populated supporting-core-index DDL proof, and once after the final measured
workload.
Each restart boundary has a preceding checkpoint so later workloads do not
inherit process and page-cache pressure from earlier write-heavy work without
first retaining that segment's cumulative counters.
Together with the pre-corpus sample, the finalizer requires the
exact ordered 45-checkpoint inventory;
deleting or reordering one checkpoint fails. A restart therefore cannot erase the preceding
cgroup segment's high-water mark or failure counters.
cgroup v2 reads `memory.current`, `memory.peak`, and `memory.events`; cgroup v1
reads `memory.usage_in_bytes`, `memory.max_usage_in_bytes`, `memory.failcnt`,
and `memory.oom_control`, treating a nonzero v1 `memory.failcnt` as the stricter
portable OOM failure. The final report records the maximum observed database
peak and exact signed headroom and requires zero OOM and OOM-kill events in
every segment. Linux defines `memory.max` as the hard limit while allowing
usage to exceed it temporarily under some circumstances, so a negative signed
headroom is retained rather than mislabeled as a failed hard limit. The
whole-run peak has no tighter cache-sensitive threshold beyond the exact hard
1-GiB/no-swap contract.

The persistent WordPress service has its own exact ordered two-checkpoint
contract: once before corpus work and once after the final measured workload.
Both checkpoints must retain the original 64-hex container ID, cgroup version,
Docker `StartedAt` timestamp, positive host PID, and restart count. Docker can
preserve an ID while restarting a container and resetting its cgroup counters;
any lifecycle-token drift therefore fails even when the ID is unchanged.
Because that container is never restarted, its final cumulative high-water
mark is the whole measured-workload peak. It must remain within the hard 512-MiB/no-swap
limit with exact remaining headroom and zero limit, OOM, and OOM-kill events at
both checkpoints. This covers Apache and every simultaneous `docker exec` PHP
writer in the persistent service. Ephemeral WP-CLI and HTTP load-generator
containers are separate and remain fail-closed through their process exit and
PHP limits. The 128-MiB PHP
limit is a per-process allocator ceiling, not a claim that aggregate container
RSS is below 128 MiB; no tighter cache-sensitive WordPress-container peak is
invented. The final checkpoint covers all measured workloads and precedes the
report-assembly PHP process, which still cannot publish PASS if it fails.
The eight REST clients use a source-hashed standalone cURL harness in separate
ephemeral containers on the same Docker network, so client memory is not
misreported as server memory and only Apache loads WordPress for reader
requests. Each artifact retains its own PHP and RSS peaks. The two writers
still load the production queue and indexing runtime in independent processes
inside the persistent server cgroup.
The runner compares the mounted prefork configuration byte-for-byte after
startup. Its eight-worker maximum serves every measured reader concurrently,
while one to two spare children replace Apache's default five-to-ten idle
children instead of spending the low-resource server budget on unused PHP
runtimes.

Finalization revalidates the complete raw image/cgroup artifact, including its
empty failure list. A dirty local smoke is explicitly marked
non-acceptance. It may record an explicit image override for diagnostics, but
the image ID and every available repository/manifest digest must remain well
formed and internally consistent. An override can never satisfy a clean release
gate.

The runner does not pretend that GitHub-hosted storage is a reproducible
low-end disk: block IOPS and throughput remain host-provided and unthrottled.
Absolute latency numbers therefore describe only the recorded pinned
CI/Docker run, not a universal hosting SLA. Statement counts and bytes, rows
examined/sent, result cardinalities, PHP/container memory, CPU quota, and
no-swap gates are the portable complexity contract. Evidence records this I/O
scope plus the GitHub runner image identifiers so PR descriptions cannot quote
CI latency as hardware-independent performance. A clean lane also fails unless
the Linux/X64 hosted-runner image identity is present rather than an `unknown`
placeholder; dirty local diagnostics may record their explicit local host.

Warm measurements use 20 warmups and 200 individual samples. Before every one
of the ten buffer-pool-cold samples, the proof reads and checksums all 8,192
64-KiB payloads in a dedicated 512-MiB InnoDB relation—exactly twice the
declared buffer pool—and retains its full-scan plan and duration. The relation
is removed and its absence verified before concurrency. These counts never
scale down with corpus size: every profile runs the same 20 warmups, 200 warm
samples, ten conditioned cold samples per case, and 100-request idle HTTP
baseline. Eight concurrent
clients run the fixed query mix while two writers reconcile disjoint 20-post
assignments. All ten processes must publish readiness before the coordinator
releases one run-ID-bound monotonic start/deadline window. The window is 62
seconds so the measured intersection of all ten workers must still be at least
60 seconds; ten independently long runtimes do not prove concurrency. Every
reader must remain on its frozen result oracle. A writer queues its next
20-post generation only after its previous assignment drains and no more than
once every 15 seconds. It stops creating generations during the final five
seconds but keeps running the production worker. This measures real publication
and lease contention instead of repeatedly coalescing the same already-dirty
rows. Each writer must acquire the real lease in at least one batch, process
work, and finish with the exact last canonical excerpt, indexed timestamp/state,
and no pending assigned work. InnoDB may choose a deadlock victim under the two
independent writers; at most eight recognized deadlocks per writer may retry,
while every other batch failure remains terminal.
An epoch change between the plan and rank statements intentionally returns the
typed `wp_fts_search_unavailable` 503 rather than a mixed publication snapshot.
Each standalone client may retry only that exact response three times. It
records every HTTP attempt, each completed sample includes its retry count and
full end-to-end latency, terminal errors remain zero, and aggregate publication
retries may not exceed the number of completed logical requests.
Every process records its own elapsed time plus start, finish, overlap, samples,
and progress; the
finalizer rejects a short, non-overlapping, incomplete, or zero-progress
artifact. Quantiles use nearest rank.
In normative lowercase terms, every process records its own elapsed time; a
coordinator-only duration can never satisfy the concurrency gate.

Warm-loop Linux high-water increments are explicitly cumulative diagnostics,
not per-request peak evidence: `VmHWM` cannot be reset after an earlier warm
case raises it. Every one of the thirteen required production search shapes is
therefore repeated once in a dedicated PHP process after validation. Each child
is bound to the source SHA, release-ZIP SHA-256, harness SHA-256, lane, engine,
corpus-manifest SHA-256, preliminary-evidence SHA-256, canonical case-definition
SHA-256, result IDs/hash/score signature, and exact SQL statement shape. Its
process identity combines the Linux boot ID, PID, and `/proc/self/stat` start
ticks. Finalization requires the exact thirteen-file/case/gate inventory,
thirteen distinct process identities, every child self-hash, and no unexpected
search-memory artifact. Removing a case or gate and recomputing the outer hash
must still fail.

Those fresh children reset PHP's peak counter immediately before the measured
search. Authoritative PHP growth is peak usage after minus live usage before;
authoritative Linux growth is `VmHWM` after minus `VmRSS` before—never
`VmHWM` after minus an older `VmHWM`. Reset support is a hard runtime
precondition, not an optional downgrade. The PHP absolute peak is the maximum
of the lifetime peak captured before reset and the measured phase peak after
reset; it is never relabeled from the reset phase alone. Each child loads and
authenticates the large preliminary report only after those production counters
are frozen, so report JSON is not mislabeled as search memory. A malformed
report can therefore be discovered after the read-only sample executes, but it
still cannot publish a passing artifact. Both deltas must be at most 16 MiB,
and both absolute peaks must be positive and at most 128 MiB. Each of the forty
conditioned cold samples uses the same formula and absolute gates in its own
source-bound process, with an exact forty-file and forty-process inventory.
The ten-page maximum-valid front-end traversal likewise uses its complete fresh
process lifetime, records the raw before/after values, and is self-hashed and
source-bound. Its two complete 1.9-MiB rows require at most 24 MiB PHP/RSS
growth while retaining the same 128-MiB absolute caps. Long-lived
dependency-LOB and populated supporting-core-index-repair
measurements cannot reset Linux high water, so they deliberately use the same
conservative `VmHWM`-after minus `VmRSS`-before attribution plus the positive
128 MiB absolute ceiling.

After installing the current ZIP and building its initial index, and before its timed reindex,
the proof derives the complete index profile through both the real web PHP
runtime and a real WP-CLI bootstrap. The full profile, analyzer signature, and
Unicode-normalizer signature must match exactly. Both raw profiles and runtime
versions are retained in evidence; changing one side to conceal a production
runtime difference is not an acceptable test workaround.

The initial current index is deliberately not accepted as a reindex benchmark:
before the clock starts, the proof verifies one derived document per eligible
canonical post, an empty work queue, and no pre-existing marker, then replaces
every derived content hash and timestamp with an invalid marker. The timed
boundary includes the real WP-CLI one-scope enqueue and every later bounded
production-worker pass needed to finish it. Those passes execute in one
long-lived proof process, rather than measuring up to a thousand container
bootstraps, but each still uses the production 100-document and 20-second
worker limits. Evidence requires the enqueue to leave exactly one scope and
zero post rows, exact analyzed/indexed/committed document totals, one scope
completion, only successful worker statuses, no lock skip/failure/defer path,
zero final work, and a terminal empty dictionary-cleanup pass. The completed
drain must restore the exact document count, valid 40-byte hashes, nonzero
timestamps, and remove every marker. Throughput divides the verified number of
rebuilt rows by the entire enqueue-plus-drain elapsed time; queue acceptance or
an unchanged-hash reconciliation cannot satisfy the gate.

## Hard measured gates

At 100k, the warm ceilings follow the plans measured on each pinned engine.
They are regression limits for this runner, not a claim that MariaDB and MySQL
have identical costs for derived-table ranking:

| Warm query | MySQL 8.0 p95 / p99 | MariaDB 10.11 p95 / p99 |
| --- | ---: | ---: |
| common three-term OR | <=5,000 / <=6,500 ms | <=6,500 / <=7,000 ms |
| valid 12-group OR+prefix | <=12,000 / <=14,000 ms | <=8,500 / <=9,000 ms |
| rare-anchor AND | <=150 / <=250 ms | <=150 / <=250 ms |
| exact-anchor surface-range AND | <=500 / <=750 ms | <=500 / <=750 ms |
| exact-anchor candidate-first AND | <=500 / <=750 ms | <=500 / <=750 ms |
| selective-prefix anchor AND warm p95 / p99 | <=150 / <=250 ms | <=150 / <=250 ms |
| 20k-completion prefix | <=5,500 / <=6,500 ms | <=7,000 / <=7,500 ms |
| all distributable packs | <=5,000 / <=6,500 ms | <=6,500 / <=7,000 ms |
| impossible mandatory term | <=50 / <=100 ms | <=50 / <=100 ms |
| valid 12-group OR+prefix temporary/sort work | 0 disk temporary tables; <=8 merge passes | 0 disk temporary tables; <=8 merge passes |

Concurrency deliberately places eight HTTP readers and two writers over one
CPU per container. Broad posting scans therefore queue behind each other, and
a typed publication retry includes the rejected attempt in its end-to-end
time. These are completion bounds for that constrained load test, not
interactive latency promises:

The preceding 100-request single-client HTTP baseline uses the same case mix.
Its p95 ceilings are 1.5 / 5 seconds at 50k and 16 / 12 seconds at 100k for
MySQL / MariaDB respectively. The warm absolute gates still reject a slow
search plan; this separate baseline bounds HTTP transport overhead on the same
constrained runner.

| Profile | MySQL 8.0 p95 / p99 | MariaDB 10.11 p95 / p99 |
| --- | ---: | ---: |
| 50k | <=10 / <=15 seconds | <=25 / <=35 seconds |
| 100k | <=40 / <=60 seconds | <=90 / <=120 seconds |

The closed-loop tail ratio against one idle reader remains a diagnostic. It
can rise when idle search gets faster even if loaded latency falls, so the hard
latency bounds above are paired with a completed-request-rate floor:

| Profile | MySQL 8.0 minimum throughput | MariaDB 10.11 minimum throughput |
| --- | ---: | ---: |
| 50k | >=1.25 requests/second | >=0.9 requests/second |
| 100k | >=0.5 requests/second | >=0.3 requests/second |

The remaining scale limits are shared by both declared engines:

| Metric | Required value |
| --- | ---: |
| cold maximum per case | <=2.75× same-run warm p99, with 2,000 / 4,000 / 500 / 2,000-ms floors for OR / valid 12-group OR+prefix / AND / prefix |
| concurrent mixed HTTP p95 / p99 | engine/profile bounds above |
| concurrent errors, timeouts, wrong result sets | 0 |
| concurrent typed publication retries | <= logical requests; <=3 per request |
| concurrent writer deadlock retries / terminal failures | <=8 per writer and <=16 total for the two writers / 0 |
| concurrent mixed HTTP throughput | engine/profile floors above |
| plugin-owned search statements | <=3; impossible AND <=1 |
| missing-table request on every public adapter | exactly 1 failed plan and 0 rank/hydrate; exactly 1 readiness revocation and 1 Health latch within 2-4 option/cron controls; <=5 total plugin-owned statements; unhealthy/latch/single-event repair state present before harness restoration |
| injected plan / rank / hydration database failure | exact ordered plan / plan+rank / plan+rank+hydrate shape with only the final statement failing; no later search or core `LIKE`; exactly 1 readiness revocation and 1 Health latch within 2-4 option/cron controls; <=5 / <=6 / <=7 total plugin-owned statements; exact capability/Health/cron restoration between requests |
| largest search SQL | <=32 KiB |
| planning/ranking/hydration rows sent | <=13 / <=21 / <=20 |
| candidate-first prefix AND rank / complete-search rows examined | <=32,768 / <=65,536 |
| selective-prefix anchor rows examined / common exact materializations | <=2,048 / 0 |
| planning/ranking/hydration control evidence | every returned row; one stripped sentinel on an otherwise empty result |
| one requested identity with 4,096 unrelated dictionary identities | exactly 1 plan row; `term_identity` access |
| deleted-identity stale cursor statements | exactly 1 plan / 0 rank / 0 hydrate |
| injected non-transactional engine | rejected; 1 drop; restored InnoDB; 1 bounded global-corpus recovery scope (harness-only state restoration is fixture cleanup) |
| authoritative search PHP allocation, live RSS, PHP peak delta, and Linux `VmHWM`-after minus `VmRSS`-before | each <=16 MiB in every one of 13 fresh case processes and all 40 cold processes; <=24 MiB for the two-row maximum-valid canonical page |
| PHP peak and absolute Linux `VmHWM` | each positive and <=128 MiB in those source-bound processes |
| twenty 1.9-MiB maximum-valid setup rows | one <=1-MiB/5-second enqueue; every worker pass <=20 recognized statements, <=4-MiB SQL, <=30 seconds, <=32-MiB PHP/RSS delta, and <=128-MiB absolute PHP/RSS |
| 100k nested tags / 1.8 MiB language attribute | typed rejection; 0 SQL; <=1 second; <=16 MiB PHP allocation delta; <=128 MiB RSS |
| exact 20k markup tokens x 256 depth | 89,490-byte/0-occurrence and 99,235-byte/9,745-occurrence documents preserved; each <=2 seconds; <=16 MiB PHP allocation delta; <=128 MiB RSS |
| 20k one-byte inline text segments | typed 4-KiB lexical-run rejection; <=2 seconds; <=16 MiB PHP allocation delta; <=128 MiB RSS; exact 4,096-byte run preserved |
| 1.5 MiB / 250k encoded-word metadata source | typed occurrence rejection; <=2 seconds; <=16 MiB PHP allocation delta; <=128 MiB RSS; exact 20,000-byte sidecar preserved |
| exact 8,388,608-byte / 32,768-row lemma runtime | importer auto-shards into 2 files / 512 blocks, preserves 4,096 document morphologies with 2 opens / 64 reads / 1 MiB decoded and twelve query groups with 2 opens / 12 reads / 192 KiB decoded; 253,603 attested bytes; <=5 seconds; <=128 MiB PHP/RSS |
| compressed lemma shard expanding beyond 180 MiB without a sidecar | structural construction rejection; 0 payload hashes/reads; expansion never attempted; <=128 MiB PHP/RSS |
| 32-MiB plain/gzip lemma source line through four importers | all 8 paths reject at 64 KiB before manifest publication; each four-importer child <=10 seconds / <=32 MiB PHP peak / <=128 MiB RSS |
| original lemma source envelopes, all four importers, normal PHP and `php -n` | 64-KiB line / 64-MiB physical / 512-MiB decoded / 8,000,000-line exact boundaries accepted; every max+1 rejects without partial output; <=5 / 10 / 10 / 90 seconds; <=32 MiB PHP / <=128 MiB RSS |
| source swap/restore during all four lemma importers, normal PHP and `php -n` | 5,000 one-row chunks per private snapshot; snapshot digest equals published provenance; attacker source remains installed through manifest publication but contributes zero runtime rows; original path restored; every snapshot removed; <=30 seconds / <=32 MiB PHP / <=128 MiB RSS |
| invalid temporary parent through all four lemma importers, normal PHP and `php -n` | typed rejection before output setup; caller file retained byte-for-byte; zero output paths; <=128 MiB PHP/RSS |
| 17,000 maximum namespaced generic/PoliMorf pairs with `chunk_rows=200000` | every row retained; 2 lexical chunks; <=8 MiB keys/chunk; 6 files / 532 blocks / <=98 blocks per file / <=16 KiB decoded per block; 441,557 / 442,622 physical bytes; each child <=15 seconds / <=128 MiB PHP/RSS |
| 15,000 reverse-sorted rows with `chunk_rows=1`, both importers | exact sorted digest and boundary lookups; at least 2 merge levels; fan-in <=64; <=192 live temporary files; each child <=10 seconds / <=128 MiB PHP/RSS |
| 200,000 short distinct pairs in one generic/PoliMorf chunk, normal PHP and `php -n` | exact option accepted as one 3,000,000-byte lexical chunk; 200,001 option rejects before output; <=30 seconds / <=64 MiB PHP / <=128 MiB RSS |
| 16,384 initial generic/PoliMorf chunks, normal PHP and `php -n` | exact leaf count compacts and publishes; leaf 16,385 rejects with no manifest or output artifacts; <=15 seconds / <=32 MiB PHP / <=128 MiB RSS |
| 300,000 high-entropy generic/PoliMorf rows | typed 16-MiB physical pack rejection; no manifest or partial output; each child <=15 seconds / <=128 MiB PHP/RSS |
| CoNLL-U / UniMorph recursive source and staging maxima | 256 files / 8 KiB paths / depth 8 accepted; exact-path manifests are 60,009 / 59,973 bytes beneath the 64-KiB runtime read bound; every max+1 and symlink escape rejects before staging/hash; 1,250,000 rows and 64 MiB staging accepted, next row/byte rejects and cleans; <=128 MiB PHP/RSS |
| sparse 140-MiB audit manifest, normal PHP and `php -n` | normal exit with `invalid_pack`; bounded 64-KiB read; 16 MiB PHP peak; recursive manifest/entry/path/depth max+1 and symlink escapes bounded |
| 64-shard lemma manifest | missing/overlapping/out-of-order/unnormalized ranges reject before runtime path resolution; first/middle/last each select exactly 1 indexed shard and decode at most 1 bounded block; gap miss opens 0; normal and no-extension processes each <=2 seconds, <=32 MiB PHP allocation delta, <=128 MiB RSS |
| exact 128-file configured lemma aggregate | 32 language-scoped fields preserve 4,096 morphologies; 128 opens / 4,096 reads / 1,548,588 compressed bytes / 64 MiB decoded; 256 current-second hashes / 2,056,748 bytes; <=10 seconds / <=128 MiB PHP/RSS |
| third distinct 64-file physical pack copy | two copies / 128 files accepted; third declares 192 and raises `configured_pack_metadata` before lookup-header or payload I/O; normal PHP and `php -n` |
| stat-to-read lemma file growth | reported size 1; attestation stops after reading byte 16,777,217 with `runtime_lookup_bytes`; current-second same-stat replacement is rehashed next batch; normal PHP and `php -n` |
| worker throughput | >=20 documents/second |
| 1-100-document worker batch | <=20 total `$wpdb` statements including transaction/lease control; <=15 data statements; exactly acquire/release plus `START TRANSACTION`/`COMMIT` for a successful changed-document batch |
| mixed maximum document/scope collision | exactly 100 changed documents and <=20 complete worker statements on each document turn; each reserved scope turn <=20 complete statements |
| composed maximum worker and cron state | exactly 15 indexing/data statements and <=20 total statements; exactly 1 scheduling-control write with no event and with a later event |
| terminal corpus-completion controls | <=2 option-cache reloads; <=20 total worker statements |
| 50,000-posting writer boundary | 1 flat posting `VALUES` INSERT; 8,192 identities; executes on both supported databases |
| 50,001-posting / 8,193-identity boundaries | typed 49,152+849 / 8,192+1 splits before SQL |
| MySQL/MariaDB maximum-width 8,192-identity resolver | 32-byte language + 255-byte raw terms; 1 dictionary UPSERT + 1 resolver, each <=4 MiB; 8,192 rows sent; <=65,536 rows examined; 0 disk temporary tables |
| SQLite maximum-width writer transport | 8,192 identities reject permanently before SQL; exact `wp_` fixture boundary is 7,098 accepted / 7,099 rejected; every accepted prefix uses 1 dictionary UPSERT + 1 resolver, each <=4 MiB; 100-document/8,192-identity preflight visits each identity once under 128 MiB; maximum accepted execution also passes with 60 MiB retained suite state under 128 MiB |
| largest worker statement / transaction | <=4 MiB / <=5 seconds |
| long-lived final drain process | <=160 MiB absolute RSS under the 128 MiB PHP allocator limit; fresh worker boundary processes remain <=128 MiB RSS |
| FTS data+index bytes | <=14 KiB/eligible post in 50k/100k; <=24 KiB in the 2k diagnostic with the same fixed dense fixtures; <=1.25 GiB total |
| pending post/scope work / terminal rows | 0 / no terminal state |
| durable search-epoch metadata rows | exactly 1 singleton |
| hot-path physical schema statements | 0 |
| selective scope-page schema reads / repair statements | exactly 1 named-index metadata read / 0; all other worker schema inspection is 0 |

The cold multiplier applies to every profile. A hosted 50k MariaDB run reached
2.534× its same-run warm p99 without changing the result, plan, row, memory, or
conditioning checks. The 2.75× ceiling retains about 8.5 percent headroom over
that observed ratio; the absolute floors still reject unexpectedly slow cases
whose warm tail happens to be small.

Search work keeps two database counters separate. Performance Schema supplies
logical rows examined. The `Handler_read_*` delta supplies storage-engine read
operations, where one logical row can require several key, range, and
derived-table operations. MariaDB also charges derived-table iteration to its
statement `ROWS_EXAMINED` total more broadly than MySQL for these plans, so its
statement counter uses the storage-operation envelope while retaining the raw
value. MySQL keeps the tighter logical-row ceiling. The report never presents
the maximum of the two counters as a row count.

The relative concurrency ceiling is twice the fixed eight-reader count because
all eight readers and both writers share one CPU. It does not replace the
absolute engine/profile limits above or permit a terminal request error, timeout,
or wrong result set.

At 50k, the corresponding warm limits are:

| Warm query | MySQL 8.0 p95 / p99 | MariaDB 10.11 p95 / p99 |
| --- | ---: | ---: |
| common three-term OR | <=500 / <=550 ms | <=4,000 / <=6,000 ms |
| valid 12-group OR+prefix | <=1,000 / <=1,500 ms | <=5,000 / <=6,000 ms |
| rare-anchor AND | <=100 / <=200 ms | <=100 / <=200 ms |
| exact-anchor surface-range AND | <=300 / <=500 ms | <=300 / <=500 ms |
| exact-anchor candidate-first AND | <=300 / <=500 ms | <=300 / <=500 ms |
| selective-prefix anchor AND | <=100 / <=200 ms | <=100 / <=200 ms |
| 10k-completion prefix | <=600 / <=650 ms | <=4,000 / <=6,000 ms |
| all distributable packs | <=500 / <=750 ms | <=3,500 / <=4,000 ms |
| impossible mandatory term | <=50 / <=100 ms | <=50 / <=100 ms |
| valid 12-group OR+prefix temporary/sort work | 0 disk temporary tables; <=1 merge pass | 0 disk temporary tables; <=1 merge pass |

MariaDB's 50k hosted lane has shown broad-query p99 values as high as 5.04
seconds without a plan or row-count change. The six-second common/prefix p99
ceilings retain about 19 percent headroom over that measured host variation;
they are not latency promises and do not relax the exact plan, row,
temporary-table, or sort gates.

All structural, memory, row, and byte limits otherwise remain unchanged.

Every required MariaDB and MySQL lane also creates three adversarial rows in the
real `wp_postmeta` table. The accepted row has 511 unselected 256 KiB values
followed by one selected value; the overflow row has 512 such unselected values
followed by one selected value. Their selected values contain exact multibyte
bytes, not ASCII-only placeholders. The selected key is configured before the
initial corpus index; the fixture cannot change index configuration on a ready
corpus and then bypass the resulting full-rebuild requirement. The production
worker must index and make
the accepted selected value searchable, preserve its byte-for-byte value,
explicitly reject and acknowledge the 513-row document, and drain both work
generations. The first measured batch must isolate its permanent-rejection
phase: it acknowledges only the overflow generation, records that rejection,
and returns the analyzed accepted generation ready. One bounded successor then
indexes and acknowledges the accepted generation before any search or document
state is sampled. Treating the rejection as an indexed document, or treating
the accepted deferral as a drained generation, fails the exact outcome gates.
Its tagged dependency
measurement/value reads must be exactly two statements, use the `post_id` and
`PRIMARY` indexes respectively, return 1,027 measurement rows and only the one
accepted selected value, create no disk temporary table, examine at most 8,192
and 8 rows, complete within 10 seconds, and add at most 16 MiB PHP/RSS. The
measurement SQL has exactly three fixed core arms (taxonomy, metadata, and post
sentinels) plus zero, one, or two named bounded arms for active Polylang and
WPML sources. Thus it has at most five arms, never one arm per requested post,
and is at most 32 KiB after preparation. Separate real/fidelity plans for no
language plugin, each plugin alone, and both together must prove the same two
dependency statements and worker bound; the 1,027-row no-plugin artifact cannot
stand in for a plugin-active shape. Each core indexed source arm stops after
2,049 rows for the batch. Polylang first returns the raw object-first
relationship prefix, including non-language taxonomies, and also stops at 2,049;
only then may PHP classify language rows. WPML drives at most 100 requested
`wp_posts` primary-key rows into exact
`(element_type=CONCAT('post_',post_type), element_id=ID)` probes of its unique
`el_type_id` key and stops at 101 as defense in depth. The generated element
type has an explicit binary collation so differently configured WordPress and
WPML tables cannot make the comparison invalid. It never uses a `post_%`
pattern or scans the 100,000 unrelated translation decoys. PHP
therefore receives at most 6,348 rows including 100 post sentinels. It retains
only the first 513 combined rows per post, accepts row 512, rejects row
513, and defers any source frontier it cannot prove complete. A 100-post request
with all 32 selectable custom-field keys must keep the same branch and query
counts; value hydration accepts at most 512 selected identities in one batch
and defers a whole-document suffix beyond that bound. Distinct quote-heavy
191-byte keys may reduce the processed prefix, but
must never enlarge the SQL packet or prevent the first document from advancing.
The provider-plan artifact executes the complete prepared production UNION for
none, Polylang, WPML, and both providers, then extracts the already-prepared
provider branches for exact Performance Schema attribution and JSON plans.
MariaDB's WPML table access must name `el_type_id`, avoid `ALL`, and estimate at
most 100 translation rows. MySQL may instead expose the forced lookup as `ALL`
plus `range_checked_for_each_record`; that shape passes only when
`possible_keys` contains exactly `el_type_id` and the exactly attributed
statement returns 100 rows, examines at most 300 rows, and creates no disk
temporary table or sort. The Polylang boundary uses four requested posts with
exactly 512 non-language relationships each plus one explicit 2,049th cursor sentinel
on a fifth post. Its raw branch must return all 2,049 rows through
`PRIMARY`; the first four posts remain complete while only the sentinel post is
deferred. Filtering to language rows before counting that raw frontier is a
failure.
The third row is first indexed with a short multibyte selected value, then grows
to more than 80 KiB between measurement and hydration. MySQL/MariaDB must apply
`LEFT(CAST(value AS BINARY), bucket)` (SQLite uses a BLOB slice), so the actual
transport equals the old power-of-two byte bucket and remains strictly below twice
the measured bytes even when the growth consists of multibyte characters. The
length disagreement must defer the whole generation, leave it dirty and invisible
under both its old and new tokens, and retain one retryable work row. An unchanged
retry must then commit, drain that row, preserve the exact grown value, expose only
the new token, finish each worker attempt within ten seconds, stay within the
16 MiB delta/128 MiB absolute memory gates, and keep both dependency statements
below 32 KiB.
The statement is valid MySQL/MariaDB SQL and uses no CTE, window function,
lateral join, JSON table, OFFSET, or caller-created temporary table. The
captured SQL, Performance Schema events, `EXPLAIN FORMAT=JSON`, worker summary,
actual LOB lengths, search result, rejection record, and complete fixture
cleanup are retained in evidence. A fake database or small placeholder value
cannot satisfy this gate.

For every warm case, the separately instrumented execution must return the
exact IDs, score signature, serialized payload hash, `has_more`, and cursors of
the measured execution. Its ordered wpdb SQL SHA-256 list must equal both the
measured execution's captured SQL list and the ordered completed SQL text read
from Performance Schema, statement for statement. A fast measured search plus
an unrelated instrumented search cannot satisfy the gate. Performance Schema
is configured to retain exactly 32,768 bytes of SQL text, the complete maximum
accepted search-statement width, so a truncated event cannot create false
identity. Its global statement-history ring is fixed at 2,048 events. Each
attributed callback is read immediately and runs without the later concurrency
workload. The proof fails unless the complete tagged interval occupies at most
half of that ring and records its used rows and remaining headroom. This keeps
the interval-loss check explicit without MySQL's OOM-inducing default
10,000-row SQL-text allocation. The separate 2,003-event targeted-scope sweep
also requires exact ordered server attribution, so the global ring cannot be
reduced further. Every attribution query uses that global ring; unused
per-thread history and digest summaries are therefore fixed at zero. The
instrumented-thread reserve is fixed at 128 for the constrained 24-connection
server, and every runtime assertion requires both positive capacity headroom
and a zero `Performance_schema_thread_instances_lost` counter. The memory
reduction therefore cannot silently discard a connection's statements.
The runner fixes statement consumers to current `YES`, per-thread history `NO`,
and global history-long `YES`. Because both supported servers reset at least
one of those consumers during a restart, the runner reapplies and verifies the
exact state after every cold-cache restart. Validation, cold-eviction
preparation, and each cold sample assert the loss counter and consumer state at
both entry and exit, before the next restart can erase either failure signal.
Attribution that starts with a fresh HTTP connection additionally requires its
first retained per-thread event ID to be exactly one; a wrapped ring cannot be
misreported as the connection's real beginning.

Every `<= 3` search-path count and its wpdb/Performance Schema identity proof
covers the complete recorded callback, including `START TRANSACTION`, `BEGIN`,
`COMMIT`, and `ROLLBACK`. Only the harness-owned Performance Schema and
`SHOW SESSION STATUS` probes outside that callback are excluded. The same rule
applies to warm, cold, cursor, terminal, pack-cardinality, public-adapter, and
WP-CLI measurements, so transaction control cannot be filtered out of the
statement ceiling.

Performance Schema must retain exactly one tagged event for each expected
planning, ranking, and hydration statement, including the per-statement rows
sent above. Both engines run `EXPLAIN FORMAT=JSON` for every tagged statement;
MariaDB additionally runs `ANALYZE FORMAT=JSON` for ranking, while MySQL relies
on the completed Performance Schema event plus `EXPLAIN`. A streamed SQL token
scan maps every physical FTS relation in the emitted statement to its plan
access. The expected and observed FTS table sets must match, every access must
have a key, no FTS access may use `ALL`, and the ranking statement must contain
only the construction-known one, two, or four posting relations for its query
shape. The four-relation prefix-AND shape is two copies of the rare-anchor scan,
one exact candidate/key probe, and one cost-selected prefix arm.
The server may set statement-level `NO_INDEX_USED` for bounded derived
relations; each statement permits at most one such flag while the physical FTS
relations remain bound to the exact keyed `EXPLAIN` checks above.
This makes per-term posting-subquery fanout and candidate-ID lists hard
failures rather than conventions inferred from a query count.

The captured plans and metrics must also prove:

- exact planning resolves the at-most-twelve requested identities through
  `term_identity`; every prefix plan contains exactly one indexed surface
  `SUM(doc_freq)`, reads no postings, sends one aggregate row rather than
  completions, and examines no more than 21,000 dictionary/control rows against
  the 20,004-surface completion fixture;
- multi-group AND chooses the least-cost logical group from the bounded exact
  identities and the one surface-range aggregate. Planning never scans
  postings to choose that join order;
- rare AND examines no more than 8,192 posting rows and starts from the rare
  group;
- the deliberately out-of-order rare exact anchor proves the range-first side
  of the driver comparison. Its constructed prefix has exactly 9,900 / 103,500
  / 201,000 physical postings in the 2k / 50k / 100k lanes, no more than the
  rare anchor's 131,072 / 262,144 / 524,288 candidate-posting upper bound. It
  drives `term_identity` to posting `PRIMARY`, intersects the exact candidates,
  and retains the 30k / 175k / 350k complete-search row gates;
- the one-candidate query proves the candidate-first side against that same
  broad prefix. Its prefix posting sum is greater than the anchor's 8,192-row
  upper bound in every lane, so the rank statement must drive
  `post_term` from the candidate and classify terms through dictionary
  `PRIMARY`; a full prefix posting scan fails the SQL/EXPLAIN gates. Rank work is
  at most 32,768 rows and the complete three-statement search at most 65,536.
  The physical gate requires 4,000–4,096 lexical plus 4,000–4,096 surface rows,
  and therefore 8,000–8,192 candidate postings in total;
- a corpus-wide exact group combined with a one-document final prefix anchors
  the prefix, scans that range once, and performs only page-independent
  `(post_id,term_id)` probes for the common exact group. Its complete public
  search remains exactly three statements, examines at most 2,048 rows, and
  must contain no materialized scan of the common exact posting range;
- common OR examines no more than 1.5× summed document frequencies plus 100k
  visibility/document probes (520k for the defined corpus);
- prefix examines no more than 350k dictionary, posting, and visibility rows and
  uses one dictionary range;
- an impossible mandatory exact group, an active scope, or a rank-time control
  revocation must stop before broad surface postings: plan/rank examines at most
  256 rows and sends no revoked rank rows. The impossible plan retains its
  bounded surface range in SQL, while an engine may prune that dictionary
  access after the missing mandatory identity proves the result empty;
- planning never sends prefix completions to PHP.

## Write, failure, and repair gates

The worker statement gate wraps the entire production worker call in the
WordPress `query` filter and counts every statement it observes: source reads,
scheduling/option access, queue access, all FTS reads and writes, and
transaction control. Evidence labels that complete `$wpdb` scope, separately
reports the FTS-data subset, and requires the exact lease and transaction
control counts so application work cannot be hidden behind a narrow table-name
filter or excluded control statements.

Real `EXPLAIN FORMAT=JSON` plans for both the direct-work and scope-work claims
must use the declared work indexes without a production-table full scan.

The statement and five-second transaction gates are measured on an intentionally
changed batch, not whatever queue happens to remain after concurrency. After
draining prior work, the proof selects the 100 smallest public corpus sources
to isolate the document-count ceiling from the separately measured aggregate
posting and term ceilings. It mutates their canonical excerpts, records all
pre-write hashes, enqueues them in one statement, and runs exactly one
100-document worker pass. That pass must report 100 attempted, processed, and
analyzed documents, zero unchanged documents and failures, 100 rewritten
hashes, and an empty queue.

The prior drain may traverse the entire corpus after a taxonomy edit. Every
worker pass still contributes its counts and maximum statement, duration, and
plan measurements, but the proof does not retain every query hash and server
event in memory until exit. Detailed per-batch records begin with the fixed
100-document and mixed-work fixtures below. This keeps the long-lived proof
process proportional to one worker batch rather than to the number of scope
pages it has already completed.

That isolated batch is not allowed to hide composition cost. The drain then
builds two real corpus-scope collisions with **100** newly changed direct documents,
so each claim contains the maximum direct batch plus one scope. The
continuous-arrival alternation is measured over every `$wpdb` statement. On
the unmarked collision, the scope persists `scope_turn`, the 100 documents all
analyze and rewrite, and the complete call uses at most 20 complete worker statements,
including lease and transaction control. Before the next call, the
proof mutates and enqueues the same 100 sources again. The marked collision
must release all 100 co-claimed documents with one set write and spend its turn
on scope work in no more than 20 statements: in the active fixture the scope cursor advances
and clears the marker; in the terminal fixture the exhausted corpus scope is acknowledged
with exact profile/incarnation provenance. The
new document generations remain ready and are subsequently drained. This
three-step evidence proves progress on both sides under a continuously renewed
direct backlog; a permanently post-first or permanently scope-first policy
cannot pass by testing the two paths separately.

The fair-turn proof is followed by a stronger composition, not a collection of
independent maxima. One real document replaces an existing 4,096-lexical plus
4,096-surface frontier with a disjoint frontier of the same size. Five more
documents each contain a valid 1.9 MiB canonical source, so the six-post source
aggregate exceeds 8 MiB. The batch also has one selected dependency value, one
unmarked filtered scope, and a prior `content_failure` on the maximum document.
The claim/source-snapshot query must therefore decline the aggregate transport;
exactly one conditional source query may then hydrate the complete prefix that
fits the fixed byte budget. Exactly one dependency-measurement query, one
dependency-value query, and one existing-posting frontier query follow. The writer
admits the maximum document, defers all five suffix documents, and combines the
scope-turn publication with suffix release in one set update.

That exact heavy path runs twice. First, the proof models WordPress having
removed a due event before callback entry; the callback must create its
successor with exactly one cron-option write. It then mutates the same six
documents back across the maximum identity frontier and installs a later existing singleton.
Bringing the event forward must replace it with exactly
one cron-option write, not clear then add it with two writes or attempt a third
restore write. Each callback reports exactly 15 indexing/data statements,
exactly one scheduling-control statement, exactly two lease controls, exactly
two transaction controls, and at most 20 statements in the complete `$wpdb`
scope. Scheduling controls are reported separately only to make attribution
explicit; they remain included in the 20-statement absolute total. Successful
acknowledgement reports that the prior failure was resolved, but optional
health-history cleanup is deferred rather than appending a post-transaction
`wp_options` query. Both passes must preserve 8,192 postings, change the content
hash, drain every deferred generation, and clean every fixture row. Performance
Schema must attribute one server event, including rows examined/sent and server
duration, to every statement counted in each complete callback.

An ambiguous COMMIT has a separate hard two-outcome proof. The injected
`rejected` and `applied-but-reported-failed` outcomes each execute one COMMIT,
one best-effort ROLLBACK, 19 direct statements, and one cron write, remaining at
20 complete statements. Neither outcome may append a failure, release, scope
yield, or health write after the ambiguous publication boundary. A rejected
COMMIT retains the pre-transaction document/epoch and exact leased generations;
an applied-but-reported-failed COMMIT retains the replacement, epoch, atomic
acknowledgement, deferred suffix, and scope-turn publication. Both retain the
writer capability and schedule no earlier than its expiry. When that capability
expires, stale writer-lease takeover is a control-only phase: it processes zero
documents, touches only the bounded option lease, uses at most five direct
statements plus one cron write, and releases its replacement lease. Only the
following invocation may claim document work, and that resumed maximum writer
still includes cron persistence inside the 20-statement ceiling.

The storage transaction has an independent exact-bound real-wpdb proof. Six
maximum documents share the same 4,096 lexical and 4,096 surface identities;
an 848-lexical-posting tail makes the seventh document land on exactly
**50,000 postings** and **8,192 identities**. That boundary must commit in one
posting `INSERT`, at most twelve total statements, at most 4 MiB for any
statement, at most five seconds, and at most 128 MiB PHP peak. The posting
statement must contain exactly 50,000 flat three-column numeric `VALUES` tuples,
report exactly 50,000 affected rows, have a maximum parenthesis depth of one,
and contain no `SELECT`, `UNION`, or `FROM`. The supported database lanes
execute this exact statement under the stock thread stack; rebuilding the
constant input as thousands of `SELECT ... UNION ALL` arms cannot satisfy
acceptance.

Changing the tail from 848 to 849 creates exactly **50,001 postings** while
retaining 8,192 identities. The call must raise the typed aggregate split before
SQL and identify the exact 6-document/49,152-posting and
1-document/849-posting partitions. A separate maximum document plus one
disjoint lexical identity creates exactly **8,193 identities/postings** and
must split 8,192/1 before SQL. Executing all four partitions must retain every
posting and exact per-kind document frequency. Per-document preflight also
proves 4,096 lexical and 4,096 surface identities are accepted, while a 4,097th
identity of either kind is rejected before SQL with its typed limit reason.

The short-token identity fixture cannot prove the largest legal resolver
packet on MySQL/MariaDB. A second real transaction writes 8,192 distinct identities whose
canonical language is exactly 32 bytes and whose raw terms are exactly 255
bytes. Lexical terms contain repeated quote, backslash, percent, and control
bytes so a friendly all-ASCII word cannot conceal escaping or packet-splitting
behavior. The transaction must use exactly one tagged dictionary increment and
exactly one tagged dictionary-id resolver; each statement is at most 4 MiB,
and the resolver itself must be at least 3.5 MiB so a smaller substitute cannot
pass. Performance Schema must observe exactly one resolver event, 8,192 rows
sent, at most 65,536 rows examined, no disk temporary table, and at most five
seconds of server time. The stored table must contain exactly 4,096 lexical and
4,096 surface rows at the 255-byte width, and cleanup must leave zero document,
posting, or term rows. This transaction executes on every required real
MySQL/MariaDB lane and remains under the 128 MiB PHP/RSS ceiling.

SQLite's hexadecimal BLOB literals are intentionally not allowed to turn that
same logical update into multiple dictionary writes. Before the existing-posting
frontier read or `BEGIN`, one linear pure-PHP pass accounts for every literal,
tuple separator, ordinal, and document-frequency digit. A single document that
cannot fit one dictionary UPSERT and one resolver is a permanent
`sqlite_transport_limit` rejection; if a later document crosses the aggregate
boundary, the complete suffix is deferred to the next transaction. With the
canonical `wp_` table prefix and the same 32-byte-language/255-byte-term
fixture, 7,098 identities fit (4,194,195 resolver bytes) and 7,099 do not
(4,194,786 bytes). The 8,192-identity maximum therefore rejects with zero SQL
instead of retrying forever. The 100-document/8,192-identity containment child
must visit at most 8,192 identities, run with `memory_limit=128M`, and preserve
the one-UPSERT/one-resolver invariant for every accepted prefix. The exact
7,098-identity execution must also complete with 60 MiB of unrelated retained
suite state under the same 128 MiB limit. Product renderers append rows directly
and release validation-only maps before the transaction; the SQL fake decodes
the generated dictionary and resolver relations as streams rather than copying
their complete clauses and row graphs. This is a SQLite adapter transport
boundary, not a weakening of the required real MySQL/MariaDB maximum-width
proof.

The independent `tests/integration/existing-posting-frontier.php` proof addresses
the inverse adversary: a new batch may be tiny while the rows it replaces are
large. It creates 57,344 real disjoint dictionary/posting rows, drains them
through the production planner/writer under a 128 MiB PHP limit, verifies the
bounded index plan and Performance Schema counters on the selected database
family, and emits exact per-pass query, logical/server row, mutation, latency,
memory, dictionary-retirement, and fixture-cleanup evidence. It then reuses the
fixture for the 157,344-row populated atomic-reset proof and records all nine
reset statements with their text, byte count, SHA-256, method, and duration,
plus exact post-swap schema and table state. The worst-case runner executes this
proof in both required database families; a missing or skipped artifact fails
acceptance.

An existing-post update, deletion, relationship change, selected-metadata
change, or taxonomy edit crosses an explicit pre-SQL dirty boundary and a
matching post-SQL promotion. A MySQL/MariaDB lifecycle executes two cheap
statements total: one primary-key work-and-epoch UPSERT before canonical SQL and
one token/generation-CAS promotion UPSERT afterward. A later same-post request
advances that same row and replaces its foreground token. The earlier request's
promotion then leaves the newer `fenced` generation and payload untouched. No
database-session advisory lock, lock-read round trip, or request-unique post
identity is part of this path. Before the first fence, the request acquires one
shared lock on a deterministic per-site POSIX file and marks the token
`guard:*`. The pre row immediately excludes the old projection. At or after its
explicit `available_at` time, the ordinary worker may recover it only after a
nonblocking exclusive probe observes no live shared owner. The probe is released
before SQL so it cannot deadlock against a request holding a database lock. A
second exclusive probe after the bounded claim write closes the owner-start race
without relying on synchronized clocks. If that probe observes a live owner, one
token-and-generation-bounded UPDATE restores every still-owned lease as an
immediately due synthetic `guard:*` fence; a concurrent newer generation is
untouched. Thus the worker returns no claim, an expired lease cannot bypass the
live guard, and the first later free probe recovers the synthetic fence. The
ordinary no-race path remains one claim write and one confirmation read.
An unavailable second probe uses the same guarded quarantine, latches Health,
and remains unclaimable until path integrity is restored; this differs from a
foreground request whose initial acquisition failed before it wrote a finite
unmarked fence. A later free exclusive probe recovers the typed synthetic fence
but never the unmarked one: no file observation can prove that a request which
acquired no capability has exited. Its post-SQL hook may promote ready work; a
killed request requires the explicit quiesced reset authority.

Same-post races therefore retain exactly one `kind=post` row and one desired
generation. Generic enqueue, explicit retry, stale worker completion, and newer
payload races must preserve the latest generation; no duplicate analysis,
replacement, or cleanup path exists. A targeted taxonomy boundary uses the
same generation CAS on its canonical subject row. Only a genuinely
global/unknown canonical scope uses a request-unique corpus sentinel, because
that sentinel represents the request's fail-closed visibility boundary rather
than a competing post identity. A guarded crashed connection leaves durable state only;
after `available_at` and automatic descriptor release, the normal bounded claim
replaces its token and the normal generation-fenced acknowledgement removes it.
An unavailable lock path produces an unmarked finite fence, latches Health and
search takeover fail-closed, and persists a distinct blocked-owner reason which
generic maintenance cannot clear. No worker auto-claims that unmarked fence,
even after path repair. Only its authoritative post hook or a quiesced
`wp fts reset-index --yes` may retire it. No process may treat any later probe
as proof that a guardless owner is dead.

All PHP processes sharing the database must share the same stable inode and
working POSIX lock implementation. The production path never chooses the first
writable directory, never unlinks the file, and never uses a database-session
lock. MySQL/MariaDB defaults to a mode-`0700` hashed site directory below
uploads; an explicit `WP_FTS_FOREGROUND_LOCK_DIR` overrides only the directory.
SQLite identity remains bound to canonical `FQDB` or `DB_DIR`+`DB_FILE`, and an
existing database-file symlink resolves to the same identity. Real subprocesses
exercise two simultaneous shared owners, last-owner graceful release,
`SIGKILL` with no deliberately inherited child descriptor, the hostile
exclusive-holder timeout with a 50 ms retry deadline and <=250 ms measured wall,
and deterministic MySQL/SQLite path identity. Deterministic queue/CAS tests hold
the same real lock descriptors while proving exact work beyond the finite
deadline, unavailable-path fallback, mid-claim owner arrival and synthetic
refencing, marked recovery after a free probe, and permanent automatic
exclusion of unmarked fences.
Descriptor/path replacement
between open and final lock validation is rejected. Replacement after successful
acquisition remains prohibited by the stable-inode deployment invariant.

The real three-connection proof retains measured SQL for seven ordered CAS
boundaries: four fences and three promotions, each exactly one statement. It
proves generations 1, 2, and 3 on one canonical row, rejects both stale
promotions, preserves a newer generic-enqueue payload, and recovers a crashed
generation through the ordinary claim path with no remaining work row.

The source-bound proof must also start a separate PHP process that holds
`LOCK_SH` on the exact production guard path. The artifact records both PIDs,
both path/device/inode observations, both queue-source hashes, the proof hash,
and a zero count for that path in the worker process's static guard map. A
same-process guard is not an acceptable substitute.

Against the real work table, that holder proof seeds one overdue `guarded` row,
one overdue operator-only `fenced` row, one unrelated due `ready` row, and 512
future ready rows so the optimizer cannot pass by treating a three-row table as
free to scan. While the child remains alive, one ordinary two-statement claim
must report the OS probe as `busy`, claim and acknowledge only the unrelated
row, and leave both protected rows at their exact generations and states. One
scheduling statement must report `busy` and project the protected rows to the
300-second watchdog. The proof then sends `SIGKILL`, observes signal 9, and
reaps the holder before another ordinary two-statement claim reports `free` and
recovers only the `guarded` row. The overdue `fenced` row must remain fenced;
one free scheduling statement must omit it and return the unrelated future due
time. Only its exact authoritative promotion may make post 46 claimable.

Every one of those five measured claim/scheduling sets retains method, elapsed
time, affected rows, SQL bytes and hash, literal-redacted SQL, every tabular
`EXPLAIN` row, selected keys, and estimated rows. Claims have exact candidate
upper bounds of 400 while busy and 500 while free; scheduling has exact derived
upper bounds of 12 while busy and 10 while free. The plans must use the
`ready`, `recoverable`, `PRIMARY`, and `claim_token` indexes required by each
shape and report zero base-work-table scans. A preliminary critical gate and a
second finalizer gate validate the complete nested artifact. The fast contract
destructively removes every nested field, statement, and plan-row value in turn
and requires every shortened proof to fail; source-shape assertions alone do
not satisfy this acceptance criterion.

The same real MariaDB and MySQL proof must show an exact handoff at two statements. A
corpus handoff with no request-owned targeted scope uses exactly two statements:
one canonical `global-corpus` scope-and-epoch UPSERT and one primary-key/token
delete of the request's exact global sentinel. The maximum shape owns one
targeted scope and uses exactly three statements by adding one primary-key/token
CAS delete for that scope. Both the successful and stale-token three-statement
shapes must publish exactly one ready canonical corpus row, preserve a newer
targeted generation when the token is stale, and leave no request-sentinel
residue. The same proof requires a production worker result of one
analyzed/indexed document and one committed canonical generation,
preservation of a completed targeted scope, and zero non-epoch metadata rows
after twenty exact requests. The worker commit must leave the canonical key
absent and the content searchable. On both engines, captured production SQL
must contain exactly one marked acknowledgement inside the writer-owned
transaction: `START TRANSACTION`, bounded writes, one generation/token-CAS
`DELETE` covering only the canonical job key, the singleton epoch UPSERT, then
`COMMIT`. The queue-row-first order matches foreground enqueue and prevents an
inverse work-row/epoch lock cycle. The acknowledgement must not join or delete the writer lease; the
same exact lease remains owned through `COMMIT` and is deleted once afterward
by a CAS on both its option name and serialized payload.
`relational-fts-mutation-generation-cas-v4` also retains the complete ordered
synthetic worker SQL stream in a `relational-fts-mutation-worker-sql-v1`
envelope. It hard-fails above 32 statements, 1 MiB for any statement, or 4 MiB
in total and binds every lossless statement to its index, byte count, SHA-256,
the aggregate byte counts, and an envelope self-hash. Final validation reparses
that stream rather than trusting the producer's ACK/lease booleans.
Every retained boundary statement records its wpdb
method, byte count, SHA-256, and at most 2 KiB of literal-redacted SQL. The
finalizer rejects a missing boundary, a hard-coded summary without statements,
an unredacted ownership token, or any count that differs from the captured SQL.
The complete real-connection process has an external 180-second `SIGKILL`
ceiling, so a database or worker deadlock cannot consume the remainder of
the six-hour database lane.

Multiple physical metadata rows and multiple sequential metadata API calls for
one post in one request share that first fence through shutdown, so 1,000 row
callbacks or 100 WooCommerce-style updates still execute exactly two total FTS
statements: one fence and one promotion. Identical updates, rejected unique additions, missing deletes, and
short-circuited metadata filters execute zero. Relationship delete/add fanout
and nested `wp_set_object_terms()` similarly produce one pre/post pair. These
claims are measured through real `wp_update_post()`, `update_post_meta()`, and
`wp_update_term()` calls, not direct invocations of plugin callbacks.

Relational document mutation has one source of truth: the bounded batch writer.
`WP_FTS_Indexer` is preparation-only. It exposes field preparation, post source
preparation, and source analysis, but no storage mutation method. An analyzed
document has exactly six fields: `doc_id`, `primary_lang`, `content_hash`,
`snippet_text`, `term_frequencies`, and `surface_frequencies`.
`replace_prepared_documents()` is the sole relational document mutation
boundary. Invalid IDs, non-canonical languages, malformed hashes, extra or
missing payload fields, and oversized values must fail before storage SQL.

Preparation is separately pure. The indexer receives **100
calls for each of eight missing/invalid authority shapes (800 total)** across
`prepare_post()` and `prepare_post_source()`. Missing or non-array `terms` or
`custom_fields` must throw the exact authority `LogicException` before an
extractor, analyzer, option, taxonomy, metadata, provider, or database call.
Another 100 valid preparations alternate empty and populated authoritative
maps. They must derive selected custom-field keys from those maps when the
caller supplies no key option, fingerprint the analyzer exactly 100 times,
perform content analysis zero times, and perform zero WordPress dependency
probes or SQL.

Post preparation reads the authoritative persisted source snapshot only. Saved
`post_content` and attached taxonomy/custom-field values feed analysis, and the
snippet source is bounded plain text from saved content. The runtime analyzer
installs no default document/query provider resolver: explicit language,
detection, and the site default remain, while hostile metadata and WPML hooks
stay at zero across real content and query analysis. Explicit caller-owned
resolver callbacks remain an extension boundary rather than hidden plugin I/O.

A real claimed generation then proves that queue payload options cannot be
lost between claim and preload. A custom-field key present only in
`payload[index_options]` must survive byte-for-byte in the durable payload,
select its metadata identity in exactly one bounded dependency-measurement
statement, and feed exactly one bounded primary-identity dependency-value
statement without repeating the key literal or causing any other postmeta
statement. Both SQL statements remain at most 32 KiB. The complete worker is
measured over **every `$wpdb` statement**, not a filtered subset: at most 20
total, at most 15 data statements, exactly two ordered lease controls
(acquire, release), and exactly `START TRANSACTION` then `COMMIT`.
The one-existing-document, `batch_size=100` short-batch proof currently has 18
ordered roles: lease acquire, claim update, claim/source snapshot, dependency
measurement, dependency values, existing-posting frontier, transaction start,
dictionary increment, dictionary decrement, bounded delete,
prepared-term resolution, posting replacement, document replacement, epoch
advance, generation/token acknowledgement, commit, bounded empty-term cleanup,
and lease release. Any unclassified or reordered query fails rather than being
absorbed by a looser count. The unique metadata token must have exactly the target's complete
normalized-surface posting after one analyzed/committed document. The fixture
restores the original metadata, reindexes it, proves the posting absent, and
leaves no work row. Public searchability is already required by the surrounding
write-path and dependency-LOB gates. This focused
composition proof is in addition to the 512/513-row, large-value dependency
boundary tests; a cheap one-row path cannot substitute for those worst cases.

A caller that already owns a bounded batch may enqueue at most 1,000 IDs with
one <=1 MiB multi-row UPSERT; larger input is rejected before SQL instead of
becoming a corpus scope. Foreground hook fanout has a separate constant query
contract measured over all FTS reads and writes, not just `INSERT`s. The work
write count is two for one target and four for 2, 1,000, or 1,001 targets. Total
post SQL is two for one target and five for 2, 1,000, or 1,001 targets;
taxonomy-scope SQL is two for one target and six for 2, 1,000, or 1,001
targets.
Exact shutdown uses one bounded post UPSERT plus one exact sentinel DELETE. A
corpus shutdown with no active targeted fence uses the two-statement canonical
UPSERT plus exact-sentinel-delete handoff above; at most one request-owned
targeted scope adds its third indexed CAS delete. Every statement remains below
1 MiB, and successful exact requests
leave zero non-epoch metadata rows. A killed request may leave its already-
durable global sentinel fenced until the ordinary bounded claim reaches its
`available_at` time and observes the automatically released owner guard; a
successful exact shutdown never converts it into a
permanent metadata tombstone.

Guarded recovery is authorized only by a free exclusive probe. `guard:*`
producer rows persist as indexed `state='guarded'`; raw/unmarked producer rows
persist as distinct operator-only `state='fenced'`. When the probe is busy or
unavailable, guarded and fenced candidate arms are both omitted from claims and
each protected state contributes one bounded watchdog scheduling arm;
ready/retry work remains runnable. When the probe is free, the bounded claim
includes `guarded` directly through the ready index and omits `fenced` entirely;
there is no token `LIKE` scan or outer token predicate. Free scheduling likewise
includes guarded and omits fenced, so operator-only debt cannot create a
permanent watchdog loop. The hostile test mixes both producer paths, proves
constant statement count plus unrelated progress while busy, then proves only
guarded recovery after `SIGKILL` and ready recovery after authoritative post-SQL
promotion.

The first persistence/activation failure latches foreground FTS I/O off for the
rest of the request: after the measured four-statement failure prefix, another
1,001 pre/post hook pairs execute zero FTS statements. Canonical writes continue
while readiness is unhealthy. A blog switch needs no lock-release statement: it
discards every old-site request token/target and leaves durable old-site fences
for guarded time-bounded recovery or explicit unmarked recovery. It must not
schedule an old-site ready-work event in the new site's cron namespace, and no
token or writer lease may authorize the new prefix. If canonical code switches
and restores a blog inside one pre-SQL/post-SQL lifecycle, a missing in-memory
relationship or global-meta marker makes the old site's post hook publish one
bounded successor. This stays
correct even when the abandoned fence expired and was acknowledged before the
canonical statement completed. Editing a term attached to 50k posts returns within 500 ms
and writes one fenced/promoted taxonomy scope row; workers expand it in keyset
batches. Before expansion, every required engine/profile lane immediately runs
the broad `prefixprobe*` surface query while that targeted scope covers the
whole corpus. Planning must detect any pending corpus, global, targeted, or filtered
scope and raise the typed unavailable error after exactly one plan statement;
rank and hydrate must not begin. The plan may examine at most 256 indexed rows,
send at most thirteen dictionary/control rows, create no disk temporary table or
sort merge pass, raise no no-index flags, and attribute its exact wpdb SQL hash
to one completed Performance Schema event. JSON `EXPLAIN` must cover the exact
`fts_terms` plus `fts_work` relation allowlist with a key and no FTS full scan.
Recording only empty IDs or timing only the taxonomy update is insufficient.

The same phase inserts 100,000 unrelated dirty rows into the real `fts_work`
table, then runs the production visibility fragment behind a 20-post candidate
driver. JSON and tabular `EXPLAIN` must show only the direct dirty anti-join on
key `dirty`; the SQL and plan must contain no relationship-table or scope
anti-join at all. The clean twenty candidates remain visible even while a scope
exists because scope fail-closed behavior belongs to the plan boundary, not a
per-candidate predicate. The one completed event examines at most 200 rows,
sends twenty, creates no disk temporary table or sort merge pass, raises no
no-index flag, and finishes within 500 ms on both clocks.

The structural negative-access proof creates a separate canonical indexed
relationship shape with 100,000 objects and 512 relationships per object:
exactly 51,200,000 physical rows. It uses MyISAM only to make construction of a
table that production must not touch practical; its bytes and seed time are not
claims about production storage or query latency. The populated repair and
concurrent-write proof below remains canonical InnoDB. With no scope, a real
hydrated broad prefix search must execute plan+rank+hydrate, return twenty results, and
contain zero references to that dense relationship table in captured SQL or
JSON plans. With one active targeted scope, the same call must raise typed
unavailable after plan only. Finally, a query-filter race inserts a targeted
scope through the real mysqli connection after plan has completed but before
rank executes. Rank's driving snapshot control must reject it before surface
postings, examine at most 256 rows, send zero rows, and prevent hydration; the
shape is exactly plan+rank. All three cases retain exact ordered wpdb to
Performance Schema SQL identity. Each client/server measurement must finish
within the larger of 2,000 ms and the 10k/20k-completion prefix p99 ceiling for
that engine/profile lane. This removes taxonomy fanout from search complexity entirely.
It does not merely budget a relationship probe per candidate.

The current schema requires three plugin-namespaced supporting indexes on
WordPress's core tables: `wp_fts_term_object(term_taxonomy_id, object_id)` and
`wp_fts_type_status_id(post_type, post_status, ID)` for scope keysets, plus
`wp_fts_visibility(ID, post_type, post_status, post_password, post_date_gmt)`
for covering per-candidate visibility reads. The proof reads their exact real
definitions before substituting any fixture. Exact namespaced definitions are
plugin-owned; a same-name different-definition collision fails closed.
Uninstall drops exact matches and leaves conflicting definitions untouched.

The populated repair proof clones WordPress's canonical posts and relationships
tables with their real InnoDB definitions, removes the three plugin-owned
composites, then populates 100,001 posts and 300,001 relationships. It redirects
WordPress's canonical core-table properties to these clones and executes the
production writer-lease,
repair, and verification path, not copied DDL. It must issue exactly the three
canonical `CREATE INDEX` statements and verify all three definitions.
Completed Performance Schema events must match all three wpdb DDL hashes exactly.

One persistent lightweight core-table writer process synchronizes at every query
boundary and reuses its database connection for all six writes without
bootstrapping a second WordPress runtime. A canonical INSERT and UPDATE during
each populated core-index build
must have a server-timer interval that overlaps the corresponding `CREATE INDEX`,
affect exactly one row, and finish
within 5,000 ms on both client and server clocks. Their reported Performance
Schema lock time is retained rather than inferred from total DDL time. This
measures whether synchronous repair blocks normal core writes; it does not
describe a 60-second ceiling without concurrent work.

That populated repair has a 180,000 ms total client ceiling, a 120,000 ms
per-statement and 180,000 ms aggregate database-server ceiling, at most 64 wpdb
statements including exactly three DDL statements, and at most 16 MiB additional
PHP and RSS high-water usage. The fixture records actual data/index bytes before
and after and requires a positive index-byte delta no larger than 128 MiB. The
exact index-health/readiness/incarnation options, public takeover signature,
and grouped durable-work cardinality must be unchanged. The real phase runs under a
1,800-second external kill, so a hung DDL or process OOM cannot masquerade as a
missing report.

The later query-plan proof uses separate disposable MyISAM posts and
relationships tables already carrying both production composites. Its
relationship table also keeps core's `PRIMARY(object_id, term_taxonomy_id)` and
a genuinely non-covering one-column `term_taxonomy_id` decoy. This removes
InnoDB's implicit-primary-key advantage and proves that production SQL names the
intended composites. That plan fixture contains three 100,000-row relationship
regions plus one explicit cursor sentinel; it is not the canonical InnoDB DDL
fixture above.

The targeted fixture has 100,000 target relationships below the cursor, one
target sentinel exactly at the cursor, 100,000 unrelated relationships above
it, and then 100,000 target members.
Production traverses exactly 1,000 full member pages plus one exhaustion page.
Each page is one direct
`scope_rel FORCE INDEX (wp_fts_term_object)` keyset, returns/examines at most
100 target members, performs no posts-table scan, creates no disk temporary
table or merge pass, sorts zero rows, and completes within 250 ms on both
clocks. Thus total work is proportional to affected membership, not the site or
relationship-table cardinality.

The filtered sparse fixture puts 100,000 posts in an unselected type/status
lane before one selected post. The selected post and exhaustion are reached in
two statements, not 1,002 raw-ID scans. The maximum shape selects eight types
and four statuses: exactly 32 `wp_fts_type_status_id` branches, each capped at
100 rows, feed an at-most-3,200-row derived relation and an outer 100-row limit.
The proof traverses all 3,200 known matches in 32 data pages plus exhaustion.
MariaDB counts those bounded lane rows in the source, `UNION`, and grouping
passes, plus the final output, so each page examines at most 9,700 rows. It
sorts at most 3,200, sends at most 100, uses no disk temporary table or merge
pass, stays below 32 KiB, and completes within 250 ms per clock. A valid-looking
11-by-3 filter cross-product is rejected before SQL because it exceeds the 32-lane contract.

Only corpus reconciliation is intentionally proportional to the complete
corpus. Its fixture walks the entire 100,000-ineligible-post gap in 1,000 raw
pages, then the eligible page and exhaustion. Each statement reads at most 100
posts and 100 retained documents through separate PRIMARY keysets and merges an
at-most-200-row derived relation. Counting both source keysets, both inner
derived scans, the grouped relation, and final output, each statement
examines at most 900 rows. It sorts at most 200 and creates no disk temporary table or merge
pass. An in-memory temporary table or bounded outer filesort is allowed for
this global merge.

Every workflow issues exactly one tagged data selector per page. Immediately
before a targeted or filtered selector it also validates the exact named index:
one narrow `SHOW INDEX` on MySQL/MariaDB, or one set-oriented metadata query
joining `pragma_index_list` and `pragma_index_info` on SQLite. Thus selective
pages execute at most two plugin statements on every supported database; corpus
pages still execute only their selector.
The proof separately matches selector and metadata wpdb hashes to completed
Performance Schema events. Each metadata
read sends at most three rows, finishes within 250 ms, and creates no disk
temporary table, merge pass, or `NO_GOOD_INDEX_USED` flag. Data selectors send
at most 100 rows, retain their per-case row/sort limits and keyed base-table
plans, and may raise the statement-level `NO_INDEX_USED` flag only for a bounded
derived table. The v2 sweep artifact retains one ordered measurement vector per
selector and metadata statement: wpdb and Performance Schema SQL hashes,
statement bytes, event ID, rows examined/sent, temporary/sort/index flags, raw
picosecond timer, derived server duration, and the selector's client duration.
Final validation recomputes every count, sum, and maximum from those vectors.
The MyISAM fixtures are removed and verified absent. All four required
database/profile lanes run this proof.

The proof first changes, indexes, finds, and restores one post through
a real save lifecycle. Its separate unchanged case queues 100 distinct
documents through one 100-ID invalidation and 1,000 repeated requeues, verifies
that they coalesce to 100 work rows, and runs the worker. It must report 100
attempted, committed, queue-processed, and unchanged generations; zero
processed, indexed, or analyzed documents; zero adverse outcomes or failures;
and zero remaining work. An unchanged acknowledgement is durable progress, not
an indexing event.

A deterministic lowest-ID poison post must not block 99 later posts. After
three passes, later posts are searchable, poison debt remains durable, and a
future watchdog exists. Removing the fault must reconcile the original desired
generation. Concurrent updates and four workers must preserve the newest
generation, postings, and document frequencies without lost work.

The poison proof also advances a generation while its predecessor is leased.
The newer generation must be immediately claimable, while stale acknowledge,
fail, and release calls must all be fenced from consuming or delaying it.
Worker summaries distinguish physical processing from durable completion:
`committed`/`queue_processed` count only successful generation-CAS
acknowledgements, while `superseded` reports storage work whose stale claim was
correctly left dirty.

Successor scheduling is part of that liveness contract, not best-effort
diagnostics. With no pre-existing queue event and WordPress scheduling forced
to return `false`, a maximum cron batch must commit its complete bounded prefix,
retain the exact unprocessed suffix, release the writer lease, leave the event
absent, and then throw `WP_FTS_Index_Successor_Schedule_Failed`. A manual batch
over future-only retry debt returns the explicit
`successor_schedule_failed=true` flag plus the same stop reason and leaves the
retry timestamp unchanged. A cron callback contending with an active manual
lease must likewise throw on a false successor result while preserving the
exact queued generation and the other worker's lease byte-for-byte. Each
complete failure invocation remains at most 20 statements and makes one
schedule attempt; it never retries inside the callback or schedules after
lease release. An empty `next_available_at()` result is a successful
no-schedule outcome. Health must classify durable work plus the absent event as
`missing` and expose the bounded recovery control.

```sh
WP_FTS_MIN_CHECKS=37 WP_FTS_FAIL_ON_PENDING=1 WP_FTS_TEST_FILTER='successor schedule failure' php indexer/tests/run.php
WP_FTS_MIN_CHECKS=6 WP_FTS_FAIL_ON_PENDING=1 WP_FTS_TEST_FILTER='empty cron successor' php indexer/tests/run.php
```

Cursor validation covers forward and reverse prefix pages with exact integer
scores, a frozen recency clock, token tampering, and replay under a changed
query, type, status, date bound, or prefix cap. Replayed or tampered cursors
must fail before ranking SQL.

Missing tables, killed transactions, maintenance-lease contention, and a MariaDB
restart must leave ordinary pages
and saves available while FTS fails closed. Repair, worker drain, and the
automatically scheduled maintenance finalizer must restore search without
manual row surgery. Normal requests and workers never run DDL.
The missing-table proof starts every REST, front-end, admin-post, Sandbox, and
Sandbox/AJAX request from the same exact published capability, Health, and cron
state. Each request must reach one attributed failing plan statement, execute no
rank or hydration, and remain within the five-statement failure ceiling above.
Before any harness restoration, hashed raw-option snapshots must prove that the
published search-ready capability is revoked, Health is `unhealthy` with
`search_runtime_failure_latched=true`, and the exact single-event schema-repair
hook is present (whether it was newly scheduled or already present). The
attributed controls must include exactly one mutation of search readiness and
one mutation of Health; arbitrary option traffic cannot substitute for those
production state transitions.
The disposable harness restores the exact three pre-fault option rows between
adapters only to isolate those five independent requests; production retains the
revocation and scheduled repair.
An additional REST proof injects one real missing-relation database error at
each of plan, rank, and hydration without renaming a production table. The
request query filter changes exactly one stage statement, and Performance
Schema must report that exact statement with MySQL error 1146 / SQLSTATE
`42S02`. The ordered search prefixes must be plan, plan+rank, and
plan+rank+hydrate respectively; all earlier statements succeed, only the final
statement fails, and it must be the sole nonzero MySQL error across every
plugin-attributed search and control statement,
and no later stage or core `LIKE` executes. Each request must
then show the same exact readiness mutation, Health latch, repair schedule, and
two-to-four option/cron controls as the missing-table case. Hashed raw snapshots
must prove exact capability, Health, and cron restoration before the next stage
is injected.
An isolated read timeout or disconnected search query latches takeover
fail-closed and schedules bounded physical/profile verification; it must not
mark a proven-ready index pending or enqueue a corpus rebuild unless that
verification finds an actual schema or profile mismatch.
Once profile drift is proven, publication is revoked before the new profile
hash or reconciliation work is persisted. A failure after each durable write
must remain unavailable and must resume one profile- and incarnation-specific
scope; an old nonempty completion timestamp or a missing scope may never publish
the new profile.

## Reports and source binding

The runner writes `relational-fts-evidence-v5` JSON containing source and ZIP
hashes, image digests, effective resources, corpus seed/hash/counts, schema and
DB bytes, raw latency samples, result IDs/hashes, SQL count/bytes/text,
`EXPLAIN`/`ANALYZE`, rows examined/sent, temporary/sort/lock metrics, PHP
allocation/RSS, worker samples, concurrency samples, fault/repair assertions,
and every expected/actual gate.
The validation phase writes `completed=false`; only the terminal finalizer may
replace it with `completed=true` and recompute the canonical evidence hash. The
runner rechecks that completion bit, source/profile/engine binding, PASS status,
and self-hash before publishing the primary report.
If any validation gate fails, that phase exits immediately after writing the
incomplete report. Every other acceptance phase likewise writes its terminal
diagnostic artifact and then exits nonzero on a failed gate, so WP-CLI adapter,
transaction-recovery, idle-HTTP, concurrent-worker, taxonomy-scope, and final
drain failures cannot consume later expensive phases or survive until the
finalizer.
The final report embeds the complete source-bound resource, mutation-statement,
and isolated-boundary artifacts plus the SHA-256 of each original file. The
terminal finalizer requires the reread mutation artifact to be canonically
identical to the complete object that validation gated, so later replacement of
an already-validated worker/cleanup field cannot pass on stale gates. The
isolated process writes the same JSON bytes to stdout and its atomic artifact;
their hashes must match. Its evidence has exactly 62 uniquely named passing
gates and a self-hash over its canonical payload. Source, dirty state, ZIP,
profile, engine, and dynamically calculated isolated-harness hash must all match
the main run.

Evidence collection fails on a dirty source unless explicitly running a local
non-acceptance smoke. Missing/null metrics, duplicate cases, an unexpected
corpus hash, skips, or a source mismatch invalidate the report.
Clean lanes package an immutable detached worktree at the recorded source SHA;
the live checkout is never reused after its cleanliness check. Failure
publication is installed before Docker/Composer/Git/tar preflight and Docker
daemon checks, so the primary path first contains an atomically written
`relational-fts-run-state-v2` `RUNNING` envelope with a stable run/lane ID,
exact stage/phase, and `completed=false` (PHP itself is required to serialize
it). Every host or Docker phase also has its own bounded timeout. If any later
phase times out, is killed/OOMed, or exits unsuccessfully, that same primary
schema is atomically replaced with `status=FAIL`, the exact runner exit/failure
class, and the last stage and phase before archive compression or container
cleanup begins. A preliminary validation report is
retained only as `.partial-evidence.json` beside the raw artifact bundle; it can
never occupy the primary path or masquerade as the completed report.
Full-traversal phases normally have a two-hour kill. The 100k MariaDB validation
phase has three hours because its fixed 20 warmups and 200 samples cover all
thirteen query shapes on one constrained CPU; the overall 19,800-second watchdog
and 345-minute workflow-step limit remain unchanged.
The completed report and raw artifact bundle are each published by atomic
rename. Failure to create either complete file turns the run into a failure
envelope; a partial archive can never accompany a successful exit.

An internal single-process watchdog signals the runner after 19,800 seconds,
then allows at most 300 seconds for synchronous failure publication, bounded
archive creation, and cleanup before `SIGKILL`. It rechecks its direct parent
immediately before either signal, holds no CI input/output pipe, and is killed
and reaped on ordinary exit. The workflow proof step is bounded at 345 minutes
inside a 360-minute job, and the always-run upload includes hidden `.context`
paths. Thus the internal terminal envelope is due by minute 335, before the
Actions step/job ceilings; a platform timeout cannot legitimately leave a stale
PASS or suppress every failure artifact.

Before the first container starts, the current source is packaged twice in
independent build directories with separate fresh Composer homes and caches.
Composer plugins and scripts are disabled, staged symlinks are rejected before
and after dependency installation, and the builder cannot inherit Composer
auth or global configuration. Acceptance requires byte-identical ZIPs and
identical sorted per-entry name/content/metadata manifests. The report binds
both ZIP and manifest hashes, the full manifest, PHP/Composer/zip/libzip/zlib
versions where exposed, and PHP/Composer binary hashes to the source SHA. The
workflow selects the maintained stable PHP 8.4 release line and exact Composer
2.9.8. Acceptance rejects prerelease or non-8.4 PHP versions and records the
exact resolved PHP patch, extension and library versions, and PHP/Composer
binary hashes, so every artifact binds the toolchain that actually produced it
instead of claiming an ineffective patch pin.
The packaged `vendor/wp-php-toolkit/full-text-search` runtime must also match
the staged `components/full-text-search` source byte-for-byte after the
documented release-only pruning of tests, raw upstream sources, and development
metadata. The builder must install that path dependency into an empty staged
vendor tree; copying a checkout's pre-existing vendor directory and allowing an
unchanged Composer version/lock reference to preserve stale runtime files does
not pass. A fresh-package worker must index the default WordPress corpus and
publish healthy searchable readiness before any mounted-source acceptance
helper runs. This catches a deterministic, internally self-consistent ZIP that
nevertheless omits the component changes under test.

The WordPress-installed plugin tree is independently enumerated immediately
after ZIP installation and again immediately before finalization. Both
`installed-tree-post-install.json` and `installed-tree-pre-finalize.json` must
contain the exact sorted package manifest names, byte counts, and SHA-256s, no
symlinks, and the same source/ZIP digest; they must equal one another and the
source-bound reproducible package manifest. Testing an adjacent mounted source
tree, or allowing runtime files to drift after installation, cannot satisfy
acceptance.

Every independently written phase artifact has a fixed schema and PASS status.
Where a phase emits gates, the list must be non-empty, structurally complete,
uniquely named, and contain every required gate. Finalization also checks the
exact reader/writer counts and worker IDs, frozen concurrency case oracles,
disjoint writer assignments, request/batch accounting, measured concurrency
duration, the explicit changed-and-analyzed 100-document batch described above,
and zero remaining work. An empty gate list or a plausible summary with missing
raw phase evidence cannot produce PASS.

The preliminary report self-hashes a `relational-fts-validation-inventory-v1`
containing its exact ordered top-level sections, exact ordered case IDs, exact
ordered gate IDs, gate count, and gate-list hash. The finalizer authenticates
the preliminary self-hash and this inventory before it consumes any PASS gate,
then requires the exact gate sequence rather than a subset. Critical mutation
publication/deletion, runtime, adapter, HTTP attribution, search-shape,
supporting-core-index-repair, schema, reindex, pack, and recovery gates remain an
independent required set, so deleting one gate and recomputing both the report
and inventory hashes still fails. Deleting a section or case likewise fails.

## Validation sequence

Each required lane performs the same fail-closed sequence:

1. Create a new persistent database volume and constrained WordPress/PHP
   containers; verify image digests, cgroup limits, database variables, source
   cleanliness, the exact allowed lane ID, source/ZIP hashes, and the
   deterministic corpus manifest. The initial `RUNNING` envelope and whole-run
   watchdog already exist before these preflights.
2. Package the current source twice in independent clean build directories and
   require byte-identical ZIPs and manifests. Install that ZIP with network
   activation, then bind the installed tree byte-for-byte to its package
   manifest.
3. Create the deterministic corpus directly under the current schema, build its
   initial current index, and verify the exact eligible-document count and empty
   work queue. Prove the mutation fence, then derive matching web and WP-CLI
   runtime profiles. Invalidate every derived row, force and time a complete
   complete rebuild, and prove every invalidated row was rewritten.
4. Verify exact four-table columns/indexes, populated supporting-core-index
   repair,
   per-site semantics, document-frequency consistency, and current request
   behavior before the search and failure boundaries run.
5. Exercise exhaustive oracle pagination, every adapter against the independent
   direct-searcher oracle, actual one-pack and all-pack runtime configurations,
   missing-table faults, huge dependency LOBs, and all warm cases for 20 warmups
   and 200 measured samples. Require measured/instrumented result parity and
   exact ordered wpdb-to-Performance-Schema SQL identity; real HTTP attribution
   covers every statement on its one tagged connection through shutdown. First
   run all thirteen production search shapes again in thirteen dedicated PHP
   processes and require their exact source/case/gate/file inventory, distinct
   Linux process identities, self-hashes, conservative peak formulas, <=16 MiB
   deltas, and positive <=128 MiB absolute PHP/`VmHWM` peaks. Treat the reused
   200-sample warm-loop `VmHWM` peak and increments only as cumulative
   diagnostics; its earlier cases cannot be reset, so the fresh child owns the
   absolute bound. Then
   verify the exact current schema and autoloaded request options, then prove fresh ready,
   impossible, nonhydrating, and hydrated requests execute exactly 0, 1, 2,
   and 3 marked search statements with zero standalone option/sitemeta reads
   at or after search begins. For
   each missing-table adapter, capture the production post-fault option state
   before restoration and prove readiness revoked, Health unhealthy and latched,
   and the exact single-event repair hook present. Then restore and verify the
   exact pre-fault capability, Health, and cron rows. Require one failed plan,
   exactly one readiness mutation and one Health mutation within 2-4
   option/cron controls, and no more than five statements in the failed-search
   prefix plus its latch controls. Then inject one real missing-relation error at each search stage
   through fresh REST plan/rank requests and a Sandbox detail AJAX hydration
   request. Performance Schema must retain the exact
   ordered plan, plan+rank, and plan+rank+hydrate prefixes with only the last
   event at error 1146 / SQLSTATE `42S02`, no other plugin-attributed statement
   with a nonzero MySQL error, no later search or core `LIKE`, the
   same exact latch/schedule controls, total plugin ceilings of 5/6/7, and exact
   restoration of all three raw option rows between requests.
6. On every required MySQL/MariaDB lane, run the aggregate writer, including the maximum-width document with 4,096
   lexical plus 4,096 surface identities, a 32-byte language, and adversarial
   255-byte raw terms. Require exactly one dictionary UPSERT and one resolver,
   each <=4 MiB; the resolver must send exactly 8,192 rows, examine <=65,536
   rows, create no disk temporary table, and finish within 5 seconds. Verify
   exact stored counts and exact zero-row cleanup. Then run the 57,344-row
   existing-posting frontier and the source-bound, self-hashed 1.9-MB source/search
   processes with the setup statement/time/memory bounds above, followed by the fresh
   externally bounded isolated process for exact 4-KiB CJK, infinite-tokenizer,
   12/13-plan, 4,096/4,097-term, and 1,000/1,001-enqueue boundaries. Verify its
   byte-identical stdout/artifact and complete cleanup before fault or
   concurrency work. Separately run the SQLite 8,192-identity permanent
   pre-SQL rejection, exact largest accepted/next rejected boundary, one-write/
   one-resolver execution, aggregate suffix split, and 100-document linear
   preflight under a 128 MiB PHP limit.
7. Before the concurrency phases, run the strongest composed worker twice:
   once without an existing cron event and once with a later existing
   singleton. Both callbacks must condition prior `content_failure`, cross the
   8-MiB aggregate-source limit, execute the bounded two-statement snapshot and
   fallback protocol, replace an 8,192-posting document, yield to a filtered
   scope, resolve the prior failure without a redundant health CAS, and execute
   exactly 15 indexing/data statements plus exactly one cron-option write while
   remaining at <=20 total wpdb statements. Exercise rejected and
   applied-but-reported-failed ambiguous COMMIT outcomes at exactly 19 direct
   statements plus one cron write; prove the stale writer-lease takeover is a
   control-only callback before ordinary work resumes. Then kill an uncommitted
   transaction. Before the kill, the child atomically publishes its Linux boot
   ID, PID, start ticks, database connection, and sentinel; after `wait`, the
   wrapper independently reads the live Linux boot ID and the child's `/proc`
   start ticks, requires them to identify the same child as the ready identity,
   requires a kill exit status 0, and after `wait` atomically records that
   observation, the exact ready-file SHA-256, signal 9, and exit status 137.
   `relational-fts-transaction-crash-v3` accepts rollback and the subsequent
   search only when that exact SIGKILL receipt validates.
   Then run conditioned buffer-pool-cold samples,
   release eight lightweight REST clients and two production writer processes
   into one shared eight-reader/two-writer
   window, and prove a >=60-second all-worker intersection plus independent
   progress and final-state parity for both writers. Then traverse the complete
   100,000-member targeted fixture, maximum 32-lane filtered fixture, and
   100,000-row corpus gap. Before those reads, run the actual supporting-core-index repair
   against populated 100,001-post/300,001-relationship canonical InnoDB clones
   while one persistent lightweight writer process performs six synchronized
   INSERT/UPDATE operations to measure write overlap and blocking. Retain
   exact DDL timing,
   attribution, storage, memory, publication,
   and readiness evidence. Prove visibility against a separate 100,000-row dirty backlog and a
   51,200,000-row relationship table that plan/rank must never reference; exercise
   unchanged work plus the intentionally changed largest-source worker batch.
   Re-enumerate the installed runtime tree before finalization. Finalization
   rejects inventory shrinkage, any missing raw artifact, wrong result, budget
   breach, unfinished work, or terminal queue row.
8. Run all four pull-request database lanes: 50k and 100k on both MariaDB 10.11
   and MySQL 8.0. Upload the report and raw phase bundle even when a lane fails,
   including the hidden `.context` path; only the four successful
   machine-readable reports are acceptance. A newer commit cancels the
   obsolete in-progress
   pull-request workflow so the four deliberately expensive lanes never
   continue burning host capacity for a revision that can no longer merge.

## Commands

```sh
# Local contract/failure smoke; --allow-dirty is never release evidence.
indexer/tools/run-relational-fts-worst-case.sh \
  --engine=mariadb-10.11 --profile=2k --allow-dirty \
  --output=.context/evidence/relational-2k.json

# Required real-database PR gates.
indexer/tools/run-relational-fts-worst-case.sh \
  --engine=mariadb-10.11 --profile=50k \
  --output=.context/evidence/relational-mariadb-50k.json
indexer/tools/run-relational-fts-worst-case.sh \
  --engine=mysql-8.0 --profile=50k \
  --output=.context/evidence/relational-mysql-50k.json

# Mandatory pre-merge boundary evidence.
indexer/tools/run-relational-fts-worst-case.sh \
  --engine=mariadb-10.11 --profile=100k \
  --output=.context/evidence/relational-mariadb-100k.json
indexer/tools/run-relational-fts-worst-case.sh \
  --engine=mysql-8.0 --profile=100k \
  --output=.context/evidence/relational-mysql-100k.json
```

The real-database command is intentionally separate from `tests/run.php`; unit
iteration must not provide an excuse to shrink or silently skip it.
