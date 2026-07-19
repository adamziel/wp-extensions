# Relational Search Acceptance

This document is the release contract for the custom WordPress/PHP relational
full-text search backend. It deliberately does not use MySQL `FULLTEXT` or an
external search service. The supported production envelope is small and medium
sites with up to 100,000 searchable posts on a low-end WordPress host backed by
MySQL or MariaDB; larger installations should use a dedicated search service.
WordPress Playground's
SQLite adapter remains a functional single-request smoke target, not a claimed
multi-request production-concurrency backend. The generation-CAS mutation proof
and 50,000-document compatibility lane run on both supported database families;
the 100,000-document boundary lane runs on MariaDB. None uses an in-memory
substitute.

A change passes only when the machine-readable real-database evidence described
below passes. A missing dependency, `SKIP`, `PENDING`, timeout, OOM, absent
metric, or incomplete evidence file is a failure.

## Search-path invariants

Every production WordPress PHP, REST, front-end, admin, Sandbox/AJAX, and WP-CLI
search path must satisfy all of these invariants. The component's File and
InMemory posting-list engines are legacy test/demo fixtures: the production
factory rejects them, and a source-level call-graph contract prevents a
WordPress adapter from bypassing that factory.

1. Analyze the query once without SQL.
2. Preserve one logical group per source occurrence. Surface, lemma, stem, and
   prefix alternatives contribute at most one `MAX` score to that group.
3. Route each occurrence to one primary language plan. Activating more analyzer
   packs must not add language plans or SQL statements.
4. Restrict prefix matching to the final group and compile it as one indexed
   dictionary range. Never enumerate completions into PHP or per-term SQL.
5. Execute at most three plugin-owned statements: bounded dictionary plus
   singleton work/epoch/scope-control planning, relational ranking/top-K, and
   page hydration. The plan statement's complete physical relation allowlist is
   `fts_terms` plus `fts_work`; calling it dictionary-only would omit the
   control rows that authenticate readiness and reject pending scopes. An impossible mandatory term
   executes at most one statement. That planning statement must still read the
   mutation epoch and authenticate any supplied cursor before the impossible
   result returns; an analyzer-empty cursor request is rejected before SQL.
6. Keep statement count unchanged at 2,000, 50,000, and 100,000 documents, with
   one pack or all distributable packs active.
7. Execute no SQL in term, alternative, prefix, language, candidate, or result
   loops. Generate no candidate-sized `IN` list or posting-subquery-per-term
   `UNION`.
8. Apply current `wp_posts` type, status, and password visibility and pending
   work exclusion before score ordering and `LIMIT`. Broad OR, single-group,
   and prefix ranking must compact posting arms first, then apply exactly one
   outer derived-document visibility join before ordering; it may not repeat
   `d_exact_match` or `d_prefix_match` visibility inside posting arms.
9. Return at most `page_size + 1` ranking rows and hydrate/snippet at most
   `page_size` rows. Warm latency samples use a 20-row page. The separate
   terminal-oracle boundary traverses both a broad exact and broad prefix result
   at the public maximum of 50 and must execute plan+rank+hydrate while returning
   and hydrating a full 50-row page, not merely an easier partial page.
10. Return `has_more` and a stable search-after cursor. Interactive search has no
    synchronous exact total and generates no deep `OFFSET`. Cursor fingerprints
    include the blog's physical index namespace, and hydration cursors advance
    across every inspected rank row even when an old oversized canonical row
    cannot be transported.
11. Perform no `SHOW`, `DESCRIBE`, `information_schema`, migration, or repair
    work during normal readiness/search.
12. Keep every valid worst-case search statement at or below 32 KiB, PHP
    allocation, live RSS, and Linux high-water RSS deltas at or below 16 MiB,
    and both PHP peak and absolute Linux `VmHWM` at or below 128 MiB.
13. Reject source text above 2 MiB, more than 20,000 analyzed occurrences,
    more than 4,096 distinct terms per document, a lexical run above 4 KiB,
    query text above 4 KiB, or a query plan above 12 groups/12 alternatives
    before an unbounded value, posting list, or SQL statement is materialized.
14. Resolve exact dictionary identities from a constant relation of at most
    twelve requested `(lang, kind, term)` tuples through the unique
    `(lang,kind,term)` identity index. The exact planning arm returns one row
    per requested identity. A final-word prefix adds one typed `kind=1` binary
    range row, so the complete planning result remains at most thirteen rows.
    Every prefix plan returns one aggregate row with the range's summed
    `doc_freq`; it reads no postings and returns no completion identities to
    PHP. Multi-group AND compares that dictionary posting-row upper bound with
    each resolved exact group and anchors the cheapest logical group. Exact
    anchors use candidate/key probes and intersect a non-anchor prefix's actual
    matching postings. A selected prefix anchor unions its exact alternatives
    with the range-led postings once, applies visibility once, and probes the
    remaining groups by `(post_id,term_id)`.
15. Authenticate the published search-ready incarnation and profile hash, the
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
All public adapters must expose the same result ordering and readiness contract.
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
traffic. The three search events must be the complete plugin-attributed
statement set.
Global/tag-only history queries or filters limited to `wp_posts`/FTS tables do
not prove a request's query count.

A separate cold-request artifact seeds a real schema-v6 state with the schema,
health, and readiness rows marked `autoload=no` and the settings, analyzer, and
custom-field override rows absent. A fresh web request must migrate that state
to schema v9, initialize the three missing overrides as stored empty arrays,
preserve the exact pre-upgrade effective profile hash and published readiness
tuple, enqueue no work, and leave exactly these seven bounded request inputs in
WordPress's autoload set: schema version, health, desired readiness,
search-ready capability, settings, analyzer overrides, and indexed custom
fields. The proof primes `alloptions`, performs real direct-SQL CAS writes for
health and search-ready capability, and requires the replacement and restored
values to be immediately visible through `get_option()` without manual cache
repair.

Four subsequent requests use new database connections and no persistent object
cache. Ready initialization executes **0 plugin-attributed statements**; an
impossible AND executes exactly plan-only (**1**); a normal nonhydrating search
executes plan+rank (**2**); and a hydrated search executes plan+rank+hydrate
(**3**). Across all four, direct plugin-caused option/site-option SQL and the
normally absent network-activation token lookup are exactly zero. The same
stack attribution runs with debug collection forced on for real front-end and
authenticated wp-admin Posts searches: each must still have exactly three
plugin statements and zero option/sitemeta SQL. Hot debug formatting must use
already-computed request state; it may not run provider option probes or fully validate/read analyzer packs
merely to render a trace.

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

## Production data invariants

The completed schema has exactly one production source of truth for each
concern:

- `fts_terms`: dictionary and global document frequency;
- `fts_postings`: one `(term_id, post_id)` posting with a precomputed impact;
- `fts_documents`: source hash and bounded result/snippet material;
- `fts_work`: post generations, claims, capped retry state, and scope work.

The completed migration leaves no production document-length,
collection-statistics, duplicate scalar/JSON metadata, tombstone, or parallel
legacy retrieval table. `(term_id, post_id)` is unique, post-first candidate
probes are indexed, normalized term ranges are indexed, document frequency
matches distinct live postings, and the last durable work record is never
removed before its desired generation commits.

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
   map. With **100** existing documents carrying **8,192 disjoint postings
   each**—4,096 lexical and 4,096 surface rows—there are **819,200** old rows.
   One post-first covering-index query scans at most **50,001** rows inside a
   derived table and returns at most seven per-post aggregates. A separate
   100,000-posting lower-key decoy forces the measured `old_posting` access to
   be a covering `range` on `post_term_impact`, rather than letting a whole-index
   scan look cheap. Performance Schema may account for both inner and outer
   query blocks, but must report at most **100,008 rows examined**, seven rows
   sent, zero disk temporary tables, zero sort-merge passes, and at most five
   seconds of server time. The isolated 50,001-row inner block may use neither
   filesort nor a temporary table.

   A full deletion pass admits six complete documents (**49,152** posting
   mutations) and defers the rest before `BEGIN`; the terminal pass admits the
   remaining four (**32,768**). The measured plan is consumed by the transaction
   without a second frontier read, and a forged or mismatched plan is rejected
   before `BEGIN`. Draining the deletion fixture takes exactly **17** passes and
   leaves zero target postings, documents, and dictionary terms while
   preserving the exact 100,000-posting decoy.

   A separate survivor fixture has six target and six external documents
   sharing **49,152** terms (**98,304** postings, initial `doc_freq=2`). One
   production pass must change all 49,152 frequencies to one, remove only the
   target postings/documents, and retain every external posting, term, and exact
   frequency. Its dictionary UPDATE must put the materialized
   `post_term_impact` driver first, `STRAIGHT_JOIN` the dictionary through
   `PRIMARY`, affect exactly 49,152 terms, examine at most 250,000 server rows,
   create no disk temporary table, require at most one bounded merge pass, stay
   at most 4 KiB, and complete within five seconds.

   The combined three-target DELETE must materialize a double-derived,
   post-first driver capped at the proven maximum of **50,100** rows (50,000
   postings plus at most 100 documents with no posting), then join the posting,
   dictionary, and document targets through `PRIMARY`. This prevents MariaDB
   from driving through the dictionary-wide `empty_terms` range. MySQL and
   MariaDB `EXPLAIN FORMAT=JSON` must name `post_term_impact`; each pass has one
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
3. Deactivation retains tables/data. Uninstall removes the four current and
   twelve distinct legacy/recoverable table names with **one idempotent `DROP
   TABLE` statement per site**. A fresh v9 install first issues exactly two
   `DROP INDEX` statements for its recorded, exactly matching core-table
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
5. `wp fts reindex` rejects both old synchronous `--batch_size` spellings. With
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
8. Normal status/Health trusts stored readiness and reports
   `counts_exact=false`; exact eligible/indexed/remaining counts are `null`.
   A logically ready init/readiness/storage/search-takeover/empty-shutdown request
   performs **0 uninstall-fence probes, 0 plugin SQL, 0 option mutations, and 0
   schedules**; the direct non-autoloaded fence probe exists only at an actual
   writer, schema, foreground-persistence, or scheduling boundary.
   A stale-schema Health render executes **0 SQL** and current-schema status
   executes **1 bounded durable-work aggregate** in the contract adapter. It
   executes no `SHOW`, `information_schema`, or corpus/document-table count.
   Only explicit diagnose/support-snapshot paths set
   `schema_verification=physical`; they still report `counts_exact=false` and
   `null` corpus counts. No operator status path exhaustively counts posts,
   documents, or remaining work. Status, diagnose, and support snapshots remain
   read-only when physical verification reports a damaged schema: they perform
   no repair, enqueue, option mutation, or queue scheduling.
   On MySQL and MariaDB, a cold explicit operator status or support snapshot is
   exactly **3 plugin statements**: one tiny
   `information_schema.STATISTICS` capability-column read, one table-bounded
   UNION snapshot covering all four FTS tables plus both owned scope indexes,
   and one bounded `fts_work` status aggregate. A healthy diagnose with a
   hydrated search page is exactly **6** after adding plan/rank/hydrate; a
   nonhydrating diagnose is at most **5**. The corresponding SQLite physical
   snapshot is one portable schema statement, so operator/support totals **2**
   including work. Real cold storage-metadata evidence must reproduce exactly
   two physical statements on MySQL 5.7, MySQL 8.0, and MariaDB 10.11. Returning
   to one `information_schema` probe per table/column/index is a statement-count
   regression even though this is an explicit operator path.
9. `wp fts delete <id>` refuses an eligible canonical WordPress post. A missing
   or ineligible post creates exactly **one durable post generation** and **no
   direct derived-table DELETE**; the shared worker performs and acknowledges
   the physical cleanup.
10. Reset is independent of stored row count. The MySQL and MariaDB proof
    reseeds the frontier target and retains its plan decoy, so it starts with
    **919,200** populated postings, **819,201 terms**, **100 documents**, the
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
    schedule-option machinery measured separately. It reports
    `reconciliation_queued=true`, and that exact scope is sufficient to restore
    readiness after bounded discovery and physical verification. Publication,
    retired-DROP, scope-UPSERT, and schedule failures remain pending and do not
    emit reset success. Operators must not queue a second filtered CLI reindex;
    `process-batch` only advances the automatic scope when a manual pass is
    needed.

Validation runs the named boundary cases, not substitutes with smaller data:

```sh
WP_FTS_MIN_CHECKS=1 WP_FTS_TEST_FILTER='schema repair performs zero' php indexer/tests/run.php
WP_FTS_MIN_CHECKS=1 WP_FTS_TEST_FILTER='optimize' php indexer/tests/run.php
WP_FTS_MIN_CHECKS=1 WP_FTS_TEST_FILTER='old-posting prefix' php indexer/tests/run.php
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
WP_FTS_MIN_CHECKS=1 WP_FTS_TEST_FILTER='lemma runtime lines' php indexer/tests/run.php
WP_FTS_MIN_CHECKS=1 WP_FTS_TEST_FILTER='whole-gzip and sidecar paths enforce' php indexer/tests/run.php
WP_FTS_MIN_CHECKS=1 WP_FTS_TEST_FILTER='exact 8-MiB boundaries' php indexer/tests/run.php
WP_FTS_MIN_CHECKS=1 WP_FTS_TEST_FILTER='compressed lemma lookup' php indexer/tests/run.php
WP_FTS_MIN_CHECKS=1 WP_FTS_TEST_FILTER='64-file lemma packs' php indexer/tests/run.php
WP_FTS_MIN_CHECKS=1 WP_FTS_TEST_FILTER='bundled multi-file lemma pack' php indexer/tests/run.php
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
| `2k` | 2,000 | small-site oracle, failure smoke, and required MySQL 5.7 compatibility |
| `50k` | 50,000 | required pull-request MariaDB/MySQL evidence |
| `100k` | 100,000 | required boundary/release evidence |

Only these clean profile/engine tuples are acceptance lanes, with stable lane
identities: `2k/mysql-5.7` (`mysql57-2k`), `50k/mariadb-10.11`
(`mariadb1011-50k`), `50k/mysql-8.0` (`mysql80-50k`), and
`100k/mariadb-10.11` (`mariadb1011-100k`). Every other tuple is rejected before
Docker starts unless `--allow-dirty` explicitly marks it diagnostic. A clean
report must carry the exact expected lane ID; success from a cheaper arbitrary
profile/engine combination cannot substitute for one of these four lanes.

The 2,000-document lane is the explicit small-site validation profile, not a
lower production-size boundary. The 50k/100k lanes prove that the same bounded
query shapes hold as a medium site grows.

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
  the prefix becomes the AND anchor and the common posting list is not scanned;
- all distributable analyzer packs active without query-plan fanout;
- 5,000 higher-impact hidden rows and 20,000 higher-impact dirty rows ahead of
  clean public results;
- an absent mandatory group, ambiguous morphology, field-impact sentinels, and
  100 pages of cursor traversal.

The former 1,024-word explosive input is a separate containment probe. It must
be rejected before FTS SQL with a typed error and no silent provider switch.
A fresh isolated 128 MiB process exercises every exact public boundary through
the production analyzer, indexer, MySQL storage, searcher, and queue:

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
  character-length work. Legacy analyzer arrays accept exactly 20,000 rows and
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
- plain and gzip lemma runtime rows accept exactly 4,096 bytes and reject byte
  4,097 with one typed reason. Whole-gzip lookup and seek-index construction
  enforce the same decoded-line boundary. A fresh 128 MiB process then creates
  valid, sorted runtime shards containing exactly 8,388,608 decoded bytes and
  proves acceptance through plain streaming, forced gzip streaming, whole-gzip
  binary lookup, and a seek sidecar. Adding one valid nine-byte sorted row must
  reject both unindexed streaming modes and whole-gzip lookup with
  `runtime_lookup_decoded_bytes`; the indexed >8-MiB shard remains supported by
  inflating one <=1-MiB block and inspecting at most 32 rows. Every operation
  must finish within two seconds, the complete eight-path process within eight
  seconds, PHP allocation growth within 32 MiB, and absolute PHP/RSS within
  128 MiB. A separate fresh 128 MiB process selects a valid
  651,493-byte gzip shard that expands to 191,900,013 bytes and has no seek
  sidecar. Lookup must stop after its 8 MiB decoded envelope in at most two
  seconds, add at most 16 MiB PHP allocation, and stay below 128 MiB absolute
  RSS instead of passing the compressed-size admission check and exhausting
  PHP while decoding;
- fresh normal and no-extension 128 MiB processes exercise the full 64-shard
  manifest limit. Missing, overlapping, out-of-order, and unnormalized ranges
  must reject during manifest validation before runtime path resolution. A
  valid 64-shard pack must return first, middle, and last terms while opening
  exactly one file and decoding no more than one 8 MiB shard per term; a gap
  miss opens zero files. The complete proof must finish within two seconds, add
  at most 32 MiB PHP allocation, and stay below 128 MiB PHP/RSS;
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
reverse pagination fixture makes two oversized legacy rows fill the complete
`K+1` window, then proves the signed progress cursor reaches the next returnable
row without repeating that empty window.

Every required MariaDB and MySQL lane repeats that identity-byte proof against
the populated corpus and retains the tagged plan, Performance Schema event, and
JSON `EXPLAIN`: 4,096 unrelated identities must still send exactly one exact
planning row, use `term_identity`, and preserve the baseline result and score
signature. The lane then deletes the requested identity and advances the epoch
in one writer-locked transaction; replaying its cursor must issue exactly one
plan statement, zero rank/hydrate statements, and raise a cursor error.
Finally it converts the real `fts_work` table to MyISAM, requires verification
to report that exact engine mismatch, and runs production `create_tables()`
under the shared writer lock. Repair must drop the incompatible table exactly
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

Analyzer-pack validation and every lazy lookup mode enforce the same maximum of
twelve lemmas for one surface, including candidates split across shards. Twelve
must round-trip. The streamed source compiler must turn thirteen or more source
lemmas into one explicit surface-to-itself ambiguity no-op and publish the
source-pair count; it must never retain a lexical first-twelve subset. A raw or
corrupt runtime containing candidate thirteen must fail validation and lookup
rather than disappear or create a thirteenth query alternative. Optional Jieba
segmentation preserves exact dictionary matching when a run has more than 32
distinct prefixes. A custom source is admitted only through explicit
fixture-only construction; production custom dictionaries are unsupported
rather than rebuilt and rehashed on each request. A fixture builds one bounded
range index and proves complete-cache admission in that one source scan;
candidate prefix maps then populate lazily from indexed ranges, each at most
once, without eviction. The pinned source uses its attested first-codepoint
range index and never scans the complete dictionary during an analyzer
invocation. The declared hard case is exactly 5,000 candidates sharing 1,333
source offsets plus 32 other prefixes: it must
finish within five seconds, add at most 16 MiB PHP/RSS, and remain below 128 MiB
RSS. Candidate 5,001 must raise `jieba_dictionary_candidates` in that same
single scan. Complete-cache admission accepts at most 350,000 candidates and
8 MiB of candidate-word bytes. Accepted fixtures retain every loaded candidate
row without eviction once its prefix range is loaded, so alternating prefixes
never reread; rejected admission is permanent for that segmenter instance, so
retrying cannot rescan the source. The shared source scanner accepts an exact
8,192-byte dictionary row and rejects byte 8,193 with
`jieba_dictionary_line_bytes`. A fresh 128 MiB
process must also fill the complete accepted 16 MiB custom-dictionary envelope
with one unterminated row and reject it during the first scan within one second,
with at most 8 MiB additional PHP allocation and less than 128 MiB PHP/RSS.
An exact 8-MiB candidate-word cache remains accepted; byte 8,388,609 raises
`jieba_dictionary_candidates`, installs no partial cache, and remains a
constant-work rejection on retry. Candidate 4,097 across two prefixes remains
resident alongside the first 4,096 rather than causing eviction or a streaming
fallback.
A separate fresh 128 MiB process supplies exactly 32 prefixes with 5,000
candidates each (160,000 rows in a 2,524,583-byte valid custom dictionary).
It must preserve a five-character dictionary-only match in one scan within five
seconds, add at most 24 MiB PHP allocation, retain all 160,000 candidates and
their exact 1,280,007 candidate-word bytes, and remain below 128 MiB PHP/RSS.
The pinned source digest and 5,071,852-byte
size must remain unchanged.

Synthetic dictionaries are not sufficient for the release boundary. CI
initializes and attests gitlink
`67fa2e36e72f69d9134b8a1037b83fbb070b9775`, its configured
`https://github.com/fxsjy/jieba` URL, the 5,071,852-byte dictionary digest, and
the 1,075-byte MIT license digest. The worst-case runner repeats that operation
inside both detached package worktrees: the current
`components/full-text-search/resources/sources/jieba` path and the immutable
baseline's historical `indexer/resources/sources/jieba` path. A source-bound
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
under a 128 MiB/60-second process ceiling. It is exactly
329,972 bytes, digest
`4c979fd244e59b8343c2e584dbd5ba062deb1f836b8ae9ca2b56b54f130b9046`,
with 11,783 source ranges. The production ZIP must contain exactly that lookup,
the pinned dictionary, and its license under the curated runtime path; it must
contain no raw Jieba checkout. A fresh process extracted from the ZIP must make
`from_pack_option(true, 'zh')` select that runtime source and lookup with zero
full-source hash scans before real segmentation succeeds.

Twenty additional posts carry exactly 1.9 MB of canonical source each. Their
padding is valid ignored HTML comment content, so the analyzer sees one bounded
visible token rather than a forbidden lexical run. Indexing must accept all
twenty, and fresh-process front-end cursor traversal must return every complete,
hash-identical body without truncation while respecting the 4-MiB hydration
transport bound.

## Constrained host

`tools/run-relational-fts-worst-case.sh` provisions and verifies:

- MariaDB 10.11, MySQL 5.7, or MySQL 8.0: 1 CPU, 1 GiB RAM, no swap, 256 MiB InnoDB buffer pool,
  32 MiB temporary tables, 24 connections, normal fsync durability, and
  Performance Schema statement history;
- WordPress/PHP: 1 CPU, 512 MiB container, no swap, 128 MiB PHP memory;
- a persistent database volume, never tmpfs;
- source-bound direct-install ZIP, image digests, corpus manifest hash, database
  variables, and effective cgroup limits in evidence.

Clean acceptance uses the exact pinned MariaDB 10.11, MySQL 5.7, MySQL 8.0,
WordPress, and WP-CLI manifest digests declared by the runner; image overrides
are rejected before Docker starts. The runner verifies that each selected
reference is the expected reference, the expected manifest digest appears in
the local image, and the image ID is a SHA-256. The database, WordPress, and
persistent WP-CLI probe container IDs must equal the IDs obtained by inspecting
those exact digest references. It probes cgroup v1/v2 from inside those live
containers. Each probe
must report exactly one effective CPU, 1 GiB database or 512 MiB PHP memory,
and zero usable swap. Finalization revalidates the complete raw image/cgroup
artifact, including its empty failure list. A dirty local smoke is explicitly marked
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
is removed and its absence verified before concurrency. Eight concurrent
clients run the fixed query mix while two writers reconcile disjoint 20-post
assignments. All ten processes must publish readiness before the coordinator
releases one run-ID-bound monotonic start/deadline window. The window is 62
seconds so the measured intersection of all ten workers must still be at least
60 seconds; ten independently long runtimes do not prove concurrency. Every
reader must remain on its frozen result oracle. Each writer must acquire the
real lease in at least one batch, process work, and finish with the exact last
canonical excerpt, indexed timestamp/state, and no pending assigned work.
Every process records its own elapsed time plus start, finish, overlap, samples,
and progress; the
finalizer rejects a short, non-overlapping, incomplete, or zero-progress
artifact. Quantiles use nearest rank.
In normative lowercase terms, every process records its own elapsed time; a
coordinator-only duration can never satisfy the concurrency gate.

After installing and migrating the current ZIP, and before its timed reindex,
the proof derives the complete index profile through both the real web PHP
runtime and a real WP-CLI bootstrap. The full profile, analyzer signature, and
Unicode-normalizer signature must match exactly. Both raw profiles and runtime
versions are retained in evidence; changing one side to conceal a production
runtime difference is not an acceptable test workaround.

The migrated current index is deliberately not accepted as a reindex benchmark:
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

At 100k on the declared MariaDB profile:

| Metric | Required value |
| --- | ---: |
| common three-term OR warm p95 / p99 | <=500 / <=750 ms |
| valid 12-group OR+prefix warm p95 / p99 | <=2,000 / <=3,000 ms |
| rare-anchor AND warm p95 / p99 | <=150 / <=250 ms |
| selective-prefix anchor AND warm p95 / p99 | <=150 / <=250 ms |
| 20k-completion prefix warm p95 / p99 | <=500 / <=750 ms |
| impossible mandatory term p95 | <=50 ms |
| cold maximum: OR / AND / prefix | <=2,000 / <=500 / <=2,000 ms |
| cold maximum: valid 12-group OR+prefix | <=4,000 ms |
| concurrent mixed HTTP p95 / p99 | <=1,000 / <=1,500 ms |
| concurrent errors, timeouts, wrong result sets | 0 |
| concurrent p95 degradation | <=2× idle HTTP |
| plugin-owned search statements | <=3; impossible AND <=1 |
| largest search SQL | <=32 KiB |
| planning/ranking/hydration rows sent | <=13 / <=21 / <=20 |
| selective-prefix anchor rows examined / common exact materializations | <=2,048 / 0 |
| planning/ranking/hydration control evidence | every returned row; one stripped sentinel on an otherwise empty result |
| one requested identity with 4,096 unrelated dictionary identities | exactly 1 plan row; `term_identity` access |
| deleted-identity stale cursor statements | exactly 1 plan / 0 rank / 0 hydrate |
| injected non-transactional engine | rejected; 1 drop; restored InnoDB; 1 bounded global-corpus recovery scope (harness-only state restoration is fixture cleanup) |
| search PHP allocation, live RSS, and `VmHWM` delta | each <=16 MiB |
| PHP peak and absolute Linux `VmHWM` | each <=128 MiB |
| 100k nested tags / 1.8 MiB language attribute | typed rejection; 0 SQL; <=1 second; <=16 MiB PHP allocation delta; <=128 MiB RSS |
| exact 20k markup tokens x 256 depth | 89,490-byte/0-occurrence and 99,235-byte/9,745-occurrence documents preserved; each <=2 seconds; <=16 MiB PHP allocation delta; <=128 MiB RSS |
| 20k one-byte inline text segments | typed 4-KiB lexical-run rejection; <=2 seconds; <=16 MiB PHP allocation delta; <=128 MiB RSS; exact 4,096-byte run preserved |
| 1.5 MiB / 250k encoded-word metadata source | typed occurrence rejection; <=2 seconds; <=16 MiB PHP allocation delta; <=128 MiB RSS; exact 20,000-byte sidecar preserved |
| exact 8,388,608-byte lemma shard / +9-byte row | exact accepted in all four modes; unindexed +9 rejected; indexed +9 accepted with <=1 MiB decoded; each <=2 seconds; complete matrix <=8 seconds; <=32 MiB PHP allocation delta; <=128 MiB RSS |
| 651,493-byte / 191,900,013-byte expanded lemma shard | typed decoded-byte rejection; <=2 seconds; <=16 MiB PHP allocation delta; <=128 MiB RSS |
| 64-shard lemma manifest | missing/overlapping/out-of-order/unnormalized ranges reject before runtime path resolution; first/middle/last each open exactly 1 shard; gap miss opens 0; normal and no-extension processes each <=2 seconds, <=32 MiB PHP allocation delta, <=128 MiB RSS |
| worker throughput | >=20 documents/second |
| 1-100-document worker batch | <=20 total `$wpdb` statements including transaction/lease control; <=15 data statements; exactly acquire/release plus `START TRANSACTION`/`COMMIT` for a successful changed-document batch |
| mixed maximum document/scope collision | exactly 100 changed documents and <=20 complete worker statements on each document turn; each reserved scope turn <=20 complete statements |
| composed maximum worker and cron state | exactly 15 indexing/data statements and <=20 total statements; exactly 1 scheduling-control write with no event and with a later event |
| 50,000-posting writer boundary | 1 flat posting `VALUES` INSERT; 8,192 identities; executes on MySQL 5.7 |
| 50,001-posting / 8,193-identity boundaries | typed 49,152+849 / 8,192+1 splits before SQL |
| MySQL/MariaDB maximum-width 8,192-identity resolver | 32-byte language + 255-byte raw terms; 1 dictionary UPSERT + 1 resolver, each <=4 MiB; 8,192 rows sent; <=65,536 rows examined; 0 disk temporary tables |
| SQLite maximum-width writer transport | 8,192 identities reject permanently before SQL; exact `wp_` fixture boundary is 7,098 accepted / 7,099 rejected; every accepted prefix uses 1 dictionary UPSERT + 1 resolver, each <=4 MiB; 100-document/8,192-identity preflight visits each identity once under 128 MiB; maximum accepted execution also passes with 60 MiB retained suite state under 128 MiB |
| largest worker statement / transaction | <=4 MiB / <=5 seconds |
| FTS data+index bytes | <=12 KiB/eligible post and <=1.2 GiB |
| pending post/scope work / terminal rows | 0 / no terminal state |
| durable search-epoch metadata rows | exactly 1 singleton |
| hot-path physical schema statements | 0 |
| worker legacy-table / physical-schema statements | 0 / 0 |

At 50k, warm p95 limits are 300 ms OR, 1,000 ms valid 12-group OR+prefix,
100 ms AND, and 300 ms prefix; its valid 12-group p99 limit is 1,500 ms. All
structural, memory, and byte limits remain unchanged.

Every required MariaDB and MySQL lane also creates three adversarial rows in the
real `wp_postmeta` table. The accepted row has 511 unselected 256 KiB values
followed by one selected value; the overflow row has 512 such unselected values
followed by one selected value. Their selected values contain exact multibyte
bytes, not ASCII-only placeholders. The production worker must index and make
the accepted selected value searchable, preserve its byte-for-byte value,
explicitly reject and acknowledge the 513-row document, and drain both work
generations. Its tagged dependency
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
`el_type_id` key and stops at 101 as defense in depth. It never uses a
`post_%` pattern or scans the 100,000 unrelated translation decoys. PHP
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
provider branches for exact Performance Schema attribution and JSON plans. The
WPML table access must name `el_type_id`, never `ALL`, estimate at most 100
translation rows, return 100 rows, and examine at most 200 rows for its two-table
post-plus-translation arm. The Polylang boundary uses four requested posts with
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
The statement is valid MySQL 5.7/MariaDB SQL and uses no CTE, window function,
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
is configured to retain at least 65,536 bytes of SQL text so a truncated event
cannot create false identity.

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
one exact candidate/key probe, and one prefix-range posting arm.
This makes per-term posting-subquery fanout and candidate-ID lists hard
failures rather than conventions inferred from a query count.

The captured plans and metrics must also prove:

- exact planning resolves the at-most-twelve requested identities through
  `term_identity`; every prefix plan contains exactly one indexed surface
  `SUM(doc_freq)`, reads no postings, sends one aggregate row rather than
  completions, and examines no more than 21,000 dictionary/control rows against
  the 20,001-surface completion fixture;
- multi-group AND chooses the least-cost logical group from the bounded exact
  identities and the one surface-range aggregate. Planning never scans
  postings to choose that join order;
- rare AND examines no more than 8,192 posting rows and starts from the rare
  group;
- both the deliberately out-of-order rare exact anchor and the one-candidate
  20,001-surface prefix AND use `post_term_impact` only for exact candidate/key
  probes, then drive `term_identity` to posting `PRIMARY` through one prefix
  range and intersect the rare candidate. They examine at most 12k, 175k, and
  350k rows in the 2k, 50k, and 100k lanes respectively: the constructed actual
  prefix postings plus two bounded rare-anchor scans, never rare-anchor DF
  multiplied by unrelated candidate postings. The one-candidate fixture is not
  sparse: a physical gate requires 4,000–4,096 lexical plus 4,000–4,096 surface
  postings, and therefore 8,000–8,192 unrelated candidate postings in total;
- a corpus-wide exact group combined with a one-document final prefix anchors
  the prefix, scans that range once, and performs only page-independent
  `(post_id,term_id)` probes for the common exact group. Its complete public
  search remains exactly three statements, examines at most 2,048 rows, and
  must contain no materialized scan of the common exact posting list;
- common OR examines no more than 1.5× summed document frequencies plus 100k
  visibility/document probes (520k for the defined corpus);
- prefix examines no more than 350k dictionary, posting, and visibility rows and
  uses one dictionary range;
- an impossible mandatory exact group, an active scope, or a rank-time control
  revocation must stop before broad surface postings: plan/rank examines at most
  256 rows and sends no revoked rank rows;
- planning never sends prefix completions to PHP.

## Write, failure, and migration gates

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
draining prior work, the proof selects the 100 largest public corpus sources,
mutates their canonical excerpts, records all pre-write hashes, enqueues them in
one statement, and runs exactly one 100-document worker pass. That pass must
report 100 attempted, processed, and analyzed documents, zero unchanged
documents and failures, 100 rewritten hashes, and an empty queue.

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
independent maxima. One real document replaces an old 4,096-lexical plus
4,096-surface frontier with a disjoint frontier of the same size. Five more
documents each contain a valid 1.9 MiB canonical source, so the six-post source
aggregate exceeds 8 MiB. The batch also has one selected dependency value, one
unmarked filtered scope, and a prior `content_failure` on the maximum document.
The claim/source-snapshot query must therefore decline the aggregate transport;
exactly one conditional source query may then hydrate the complete prefix that
fits the fixed byte budget. Exactly one dependency-measurement query, one
dependency-value query, and one old-posting frontier query follow. The writer
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
and contain no `SELECT`, `UNION`, or `FROM`. The required MySQL 5.7 lane executes
this exact statement under the stock thread stack; rebuilding the constant
input as thousands of `SELECT ... UNION ALL` arms cannot satisfy acceptance.

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
same logical update into multiple dictionary writes. Before the old-posting
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

The independent `tests/integration/old-posting-frontier.php` proof addresses
the inverse adversary: a new batch may be tiny while the rows it replaces are
large. It creates 819,200 real disjoint dictionary/posting rows, drains them
through the production planner/writer under a 128 MiB PHP limit, verifies the
bounded index plan and Performance Schema counters on the selected database
family, and emits exact per-pass query, logical/server row, mutation, latency,
memory, dictionary-retirement, and fixture-cleanup evidence. It then reuses the
fixture for the 919,200-row populated atomic-reset proof and records all nine
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
must contain exactly one writer-owned multi-table acknowledgement: `START
TRANSACTION`, the singleton epoch UPSERT, one generation/token-CAS `DELETE`
covering the canonical job key and serialized writer option, then `COMMIT`.
Every retained statement records its wpdb
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
The four public single-document `WP_FTS_Indexer` mutation methods and the four
compatibility mutations on `WP_FTS_Storage_Mysql` are exercised **100 times
each (800 calls total)** with deliberately invalid and oversized inputs. Every
call must throw the exact `LogicException` message `Set-oriented storage
mutations must use the bounded batch writer.` before post extraction, option
callbacks, analyzer signatures/content, storage reads, or wpdb. The proof
requires zero returned calls, zero wrong exceptions, zero callbacks, and zero
SQL; `replace_prepared_documents()` remains the sole relational document
mutation boundary.

Preparation is separately pure. The real relational storage receives **100
calls for each of eight missing/invalid authority shapes (800 total)** across
`prepare_post()` and `prepare_post_source()`. Missing or non-array `terms` or
`custom_fields` must throw the exact authority `LogicException` before an
extractor, analyzer, option, taxonomy, metadata, provider, or database call.
Another 100 valid preparations alternate empty and populated authoritative
maps. They must derive selected custom-field keys from those maps when the
caller supplies no key option, fingerprint the analyzer exactly 100 times,
perform content analysis zero times, and perform zero WordPress dependency
probes or SQL.

Dynamic rendering is not part of that bounded relational preparation path.
Six `prepare_post*` shapes enable blocks, shortcodes, or a render callback 100
times each. All **600** must throw
`WP_FTS_Analysis_Limit_Exceeded(dynamic_rendering_not_set_oriented)` with the
stable explanatory message before the extractor, `do_blocks()`, shortcode,
render callback, analyzer, or SQL runs. Static `post_content` and precomputed
attached fields retain the capability without executing arbitrary application
code inside the worker. The runtime analyzer likewise installs no default
document/query provider resolver: explicit language, detection, and the site
default remain, while hostile metadata and WPML hooks stay at zero across real
content and query analysis. Explicit caller-owned resolver callbacks remain an
extension boundary rather than hidden plugin I/O.

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
measurement, dependency values, replacement frontier, transaction start,
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
claims about production storage or query latency. The populated migration and
concurrent-write proof below remains canonical InnoDB. With no scope, a real
hydrated broad prefix search must execute plan+rank+hydrate, return twenty results, and
contain zero references to that dense relationship table in captured SQL or
JSON plans. With one active targeted scope, the same call must raise typed
unavailable after plan only. Finally, a query-filter race inserts a targeted
scope through the real mysqli connection after plan has completed but before
rank executes. Rank's driving snapshot control must reject it before surface
postings, examine at most 256 rows, send zero rows, and prevent hydration; the
shape is exactly plan+rank. All three cases retain exact ordered wpdb to
Performance Schema SQL identity and finish within 2,000 ms per client/server
measurement. This removes taxonomy fanout from search complexity entirely
rather than merely budgeting a relationship probe per candidate.

The scope-index migration, introduced in schema v8 and required by schema v9,
installs two plugin-namespaced supporting indexes on WordPress's core
tables: `wp_fts_term_object(term_taxonomy_id, object_id)` and
`wp_fts_type_status_id(post_type, post_status, ID)`. The proof reads their exact
real definitions before substituting any fixture. Creation intent is persisted
first in the nonautoloaded `wp_fts_scope_index_ownership` option. An exact
pre-existing namespaced index may be reused without claiming it; a same-name
different-definition collision fails closed. Uninstall drops only an exact
index whose ownership was recorded, never a merely similar site-owned index.

The migration proof clones WordPress's canonical posts and relationships tables
with their real InnoDB definitions, removes only the two scope composites, then
populates 100,001 posts and 300,001 relationships before invoking the actual
v7-to-v9 plugin upgrade. It redirects WordPress's canonical core-table
properties to these clones and executes the production ownership, writer-lease,
DDL, verification, and publication path, not copied DDL. It must issue exactly
exactly the two canonical `CREATE INDEX` statements, persist nonautoloaded ownership
before the first, and publish schema v9 only after the second definition
verifies. Completed Performance Schema events must match both wpdb DDL hashes
exactly.

Four separate WordPress processes synchronize at those query boundaries. A
canonical INSERT and UPDATE against each populated core clone must have a
server-timer interval that overlaps the corresponding `CREATE INDEX`, affect
exactly one row, and finish within 5,000 ms on both client and server clocks.
Their reported Performance Schema lock time is retained rather than inferred
from total DDL time. This measures whether synchronous activation blocks normal
core writes; it does not describe a 60-second ceiling without concurrent work.

That populated upgrade has a 180,000 ms total client ceiling, a 120,000 ms
per-statement and 180,000 ms aggregate database-server ceiling, at most 64 wpdb
statements including exactly two DDL statements, and at most 16 MiB additional
PHP and RSS high-water usage. The fixture records actual data/index bytes before
and after and requires a positive index-byte delta no larger than 128 MiB. The
exact index-health/readiness/incarnation options, public takeover signature,
and grouped durable-work cardinality must be unchanged; only logical schema v9
and the already-persisted index ownership may publish. The real phase runs under
a 1,800-second external kill, so a hung DDL or process OOM cannot masquerade as
missing evidence. Unit failpoints separately reject a same-name collision,
simulate database rejection of the second `CREATE INDEX`, resume from the one
completed index without duplicate DDL, and steal the writer lease after the
first `CREATE INDEX` to prove that the stale upgrader issues neither the second
DDL nor schema publication.

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
The proof traverses all 3,200 known matches in 32 data pages plus exhaustion;
each page examines at most 6,600 rows, sorts at most 3,200, sends at most 100,
uses no disk temporary table or merge pass, stays below 32 KiB, and completes
within 250 ms per clock. A valid-looking 11-by-3 filter cross-product is rejected
before SQL because it exceeds the 32-lane contract.

Only corpus reconciliation is intentionally proportional to the complete
corpus. Its fixture walks the entire 100,000-ineligible-post gap in 1,000 raw
pages, then the eligible page and exhaustion. Each statement reads at most 100
posts and 100 retained documents through separate PRIMARY keysets and merges an
at-most-200-row derived relation. Per page it examines at most 400 rows, sorts
at most 200, and creates no disk temporary table or merge pass. An in-memory
temporary table or bounded outer filesort is allowed for this global merge.

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
derived table. The MyISAM fixtures are removed and verified absent. All four
required database/profile lanes run this proof.

The proof first changes, indexes, finds, and restores one post through
a real save lifecycle. Its separate unchanged case queues 100 distinct
documents through one 100-ID invalidation and 1,000 repeated requeues, verifies
that they coalesce to 100 work rows, and runs the worker. It must report 100
attempted/processed/committed/unchanged documents, zero analyzed documents and
failures, and zero remaining work.

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

Missing tables, killed transactions, maintenance-lease contention, MariaDB
restart, and a failure after every migration phase must leave ordinary pages
and saves available while FTS fails closed. Repair, worker drain, and the
automatically scheduled maintenance finalizer must restore search without
manual row surgery. Normal requests and workers never run DDL.
An isolated read timeout or disconnected search query latches takeover
fail-closed and schedules bounded physical/profile verification; it must not
mark a proven-ready index pending or enqueue a corpus rebuild unless that
verification finds an actual schema or profile mismatch.
Once profile drift is proven, publication is revoked before the new profile
hash or reconciliation work is persisted. A failure after each durable write
must remain unavailable and must resume one profile- and incarnation-specific
scope; an old nonempty completion timestamp or a missing scope may never publish
the new profile.

The populated migration proof builds a 50k legacy index from the exact
immutable legacy-v3 `36a26f4ad1aaef9758922f24677069045c5291ab` ZIP, installs the pull-request ZIP, then SIGKILLs and resumes after
each of the seven physical table renames and the `v4_created`,
`reconciliation_enqueued`, `ready_verified`, and `legacy_cleaned` boundaries.
Every callback must expose the exact expected table-name set, logical schema
version, pending health/readiness state, and exact post/scope work cardinality:
prior rename mappings stay on their source names, completed mappings stay on
their legacy names, the current schema and all seven legacy tables coexist only at the defined
boundaries, and readiness remains false until after `legacy_cleaned` returns.
The killed worker writes an append-only NDJSON record for every actual batch;
final evidence merges all eleven journals with finalizer batches and rejects a
single failure, malformed/missing journal, or zero recorded progress.

Immediately after every SIGKILL and before the next migration phase, a fresh PHP
process must read an ordinary option and corpus post, run a real no-op
`wp_update_post()` save, and execute an enabled main front-end `WP_Query` for a
known corpus token. The save must leave its searchable source unchanged and
produce exactly no work before the current schema is installed or one accounted
direct-post job after schema v9 is installed. The public query must return an empty result with
`wp_fts_search_unavailable`, execute no FTS SQL and no core `LIKE`, and neither
the ordinary path nor the search path may inspect or mutate physical schema.
Each in-process save/search probe must finish within five seconds, public search
may execute at most two statements with no statement above 32 KiB, and the
fresh process has a 120-second hard wall-clock kill rather than an unbounded
test timeout.
Legacy BM25 scores are recorded as informational evidence of the intentional
scoring-model cutover. Expected v4 order and integer scores are instead computed
independently from the untouched v3 logical postings, checked exactly after
migration, repeated after cache reset, and repeated again in a fresh PHP
process. Legacy tables may be removed only after all work drains and takeover is
ready.

The same proof seeds one, two, and three deterministic searchable documents in
the three real multisite prefixes before migration. After an injected failure
on site two it verifies site-one search/DF/document state and independent v4
scores immediately, proves the recorded site-three schema and physical
term/posting/document/length/metadata/collection-metadata sentinel fingerprints
are unchanged and that raw foreign-term and foreign-posting probes are empty,
then resumes all sites. Every prefix must preserve ordered search membership,
term DFs, exact
sentinel IDs and metadata/source signatures, stable complete content hashes, and
its recorded prefix; its exact integer scores must equal a cardinality-checked
per-site v4 oracle. Content-hash stability is measured across a new durable
generation for every sentinel and a real bounded worker drain, never two
back-to-back reads of the same rows.
A 3×3 site-specific token matrix must have three populated diagonal cells and
six empty off-diagonal cells, and all six foreign term DFs must be zero. During
the entire main-site destructive migration loop and finalization, a database-
container monitor targets a 250 ms cadence while polling the persistent
`/var/lib/mysql` volume and physical `wp_fts_*` files. Each raw TSV row records
host-monotonic sample start and completion, not a fabricated scheduled
timestamp. The separately marked destructive window must have a sample whose
start is at or before it and a sample whose completion is at or after it; the
maximum observed completion-to-completion gap and maximum sample duration must
each be at most 750 ms. Evidence retains the raw TSV and its SHA-256, requires
at least twenty samples and non-empty first/final FTS footprints, and gates both
the physical FTS peak and whole-volume peak at 2.2× the larger first/final
footprint. A claimed `sleep 0.25` cadence without these measured coverage/gap/
duration gates does not pass. `information_schema` phase snapshots remain
useful diagnostics but cannot substitute for this physical high-water
measurement.

## Evidence and before/after comparison

The runner writes `relational-fts-evidence-v2` JSON containing source and ZIP
hashes, image digests, effective resources, corpus seed/hash/counts, schema and
DB bytes, raw latency samples, result IDs/hashes, SQL count/bytes/text,
`EXPLAIN`/`ANALYZE`, rows examined/sent, temporary/sort/lock metrics, PHP
allocation/RSS, worker samples, concurrency samples, fault/migration assertions,
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
finalizer. The one deliberate exception is the old-version baseline query:
timeout, OOM, or incorrect-result `FAIL` is comparison evidence that migration
validation must retain rather than an early reason to discard the current
implementation's run. The runner publishes validation output only as partial
evidence and does not spend additional cold-cache or concurrency capacity on
an already failed revision.

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
workflow pins PHP 8.4.5 and Composer 2.9.7; a toolchain change is explicit
rather than silently changing the package under the same commit. The immutable
legacy runtime source is also packaged by this current hardened builder; the
runner never executes historical packaging code that could re-enable obsolete
Composer plugin, script, authentication, or global-configuration behavior.

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
exact reader/writer counts and worker IDs, frozen baseline case oracles,
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
migration-sampling, schema, reindex, pack, and recovery gates remain an
independent required set, so deleting one gate and recomputing both the report
and inventory hashes still fails. Deleting a section or case likewise fails.

Before/after numbers use clean worktrees and source-bound ZIPs for legacy v3 at
`36a26f4ad1aaef9758922f24677069045c5291ab` and the pull-request head, identical
images/resources, and the same corpus manifest. A baseline timeout, posting-row
exception, OOM, silent
provider switch, or wrong partial result is recorded as `FAIL (<reason>)`, never
as zero or omitted. The PR description reports absolute values and links raw
artifacts; speedup ratios alone are insufficient.

Every legacy comparison artifact is schema-, source-, ZIP-, profile-, and
case-bound. If the old process dies before writing evidence, the wrapper records
its measured host wall time, 120-second ceiling, exit status, and a distinct
timeout/killed/process-failure reason. Migration evidence rejects a missing or
malformed comparison artifact even when `FAIL` is the honest legacy result. A
nonzero or timed-out process can never retain an earlier `PASS` file: the
wrapper preserves that pre-failure object only as a diagnostic sidecar and
replaces the accepted comparison artifact with measured `FAIL` evidence. A
completed isolated baseline query must also reproduce the frozen populated-v3
ordered result hash; a non-empty but partial or reordered page is
`FAIL (ResultMismatch)`, not a successful timing sample.

## Validation sequence

Each required lane performs the same fail-closed sequence:

1. Create a new persistent database volume and constrained WordPress/PHP
   containers; verify image digests, cgroup limits, database variables, source
   cleanliness, the exact allowed lane ID, source/ZIP hashes, and the
   deterministic corpus manifest. The initial `RUNNING` envelope and whole-run
   watchdog already exist before these preflights.
2. Build the immutable legacy ZIP, create and index the full legacy corpus, and
   capture bounded baseline queries with explicit timeout/OOM/result-mismatch
   failure artifacts.
3. Install the pull-request ZIP without resetting the populated database and
   bind the installed tree byte-for-byte to its ZIP manifest. Kill and resume
   every physical rename and logical migration boundary, running a fresh
   ordinary-save/fail-closed-search probe immediately after each kill, while the
   monotonic physical disk sampler proves leading/trailing coverage, <=750 ms
   completion gaps, and <=750 ms sample duration across the complete migration
   window.
4. Verify exact four-table columns/indexes, migration and multisite parity,
   document-frequency consistency, and web/WP-CLI runtime-profile parity. Force
   and time a complete current-version rebuild, then prove every invalidated row
   was rewritten.
5. Exercise exhaustive oracle pagination, every adapter against the independent
   direct-searcher oracle, actual one-pack and all-pack runtime configurations,
   missing-table faults, huge dependency LOBs, and all warm cases for 20 warmups
   and 200 measured samples. Require measured/instrumented result parity and
   exact ordered wpdb-to-Performance-Schema SQL identity; real HTTP attribution
   covers every statement on its one tagged connection through shutdown. First
   seed and migrate the cold schema-v6 request state, then prove fresh ready,
   impossible, nonhydrating, and hydrated requests execute exactly 0, 1, 2,
   and 3 plugin statements with zero plugin-caused option/sitemeta reads.
6. On every required MySQL/MariaDB lane, run the aggregate writer, including the maximum-width document with 4,096
   lexical plus 4,096 surface identities, a 32-byte language, and adversarial
   255-byte raw terms. Require exactly one dictionary UPSERT and one resolver,
   each <=4 MiB; the resolver must send exactly 8,192 rows, examine <=65,536
   rows, create no disk temporary table, and finish within 5 seconds. Verify
   exact stored counts and exact zero-row cleanup. Then run the 819,200-row
   old-posting frontier and 1.9-MB source/search processes, followed by the fresh
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
   transaction, run conditioned buffer-pool-cold samples,
   release all ten ready processes into one shared eight-reader/two-writer
   window, and prove a >=60-second all-worker intersection plus independent
   progress and final-state parity for both writers. Then traverse the complete
   100,000-member targeted fixture, maximum 32-lane filtered fixture, and
   100,000-row corpus gap. Before those reads, run the actual v7-to-v9 upgrade
   against populated 100,001-post/300,001-relationship canonical InnoDB clones
   while four synchronized INSERT/UPDATE processes measure write overlap and
   blocking. Retain exact DDL timing, attribution, storage, memory, publication,
   and readiness evidence. Prove visibility against a separate 100,000-row dirty backlog and a
   51,200,000-row relationship table that plan/rank must never reference; exercise
   unchanged work plus the intentionally changed largest-source worker batch.
   Re-enumerate the installed runtime tree before finalization. Finalization
   rejects inventory shrinkage, any missing raw artifact, wrong result, budget
   breach, unfinished work, or terminal queue row.
8. Run all four pull-request jobs: 2k on MySQL 5.7, 50k on MariaDB, 50k on
   MySQL 8.0, and the 100k MariaDB boundary. Upload the evidence and raw phase
   bundle even when a lane fails, including the hidden `.context` path; only the
   four successful machine-readable reports are acceptance. A newer commit
   cancels the obsolete in-progress
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
  --engine=mysql-5.7 --profile=2k \
  --output=.context/evidence/relational-mysql-5.7-2k.json
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
```

The real-database command is intentionally separate from `tests/run.php`; unit
iteration must not provide an excuse to shrink or silently skip it.
