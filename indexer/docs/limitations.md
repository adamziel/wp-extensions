# Limitations

This page documents the current implementation and the remaining production
caveats operators need to account for.

## Runtime Integration

- The plugin exposes WP-CLI commands when `WP_CLI` is active.
- It registers activation, deactivation, uninstall, post-save, status
  transition, trash/delete, WP-Cron queue, optional REST search, and front-end
  search replacement hooks when WordPress hook APIs are available. In multisite,
  it also registers new-site schema provisioning and site-deletion table
  discovery hooks.
- Front-end main-query search replacement is enabled by default and can be
  disabled with the `wp_fts_replace_frontend_search` filter.
- Settings > Full-Text Search controls indexed post types, automatic indexing,
  front-end and wp-admin Posts search replacement, result output defaults, and
  single-plan language routing, plus the public REST opt-in. Analyzer pack paths and custom
  field indexing remain
  option/filter configuration, and operational state such as schema version and
  pending queue state is managed internally.
- Runtime saves are processed through a bounded database-backed queue. Saves
  atomically advance a post generation, workers lease exact generations, and
  failed work retries with bounded backoff. This keeps hook work small and
  survives worker interruption, but it is not an external job queue.
- Multisite support is limited to lifecycle schema and cleanup paths: activation
  or repair affects the current site, new sites get empty FTS tables, uninstall
  discovers at most 100 site IDs per page and removes plugin tables/options per
  site, and WordPress site deletion can discover those tables. A failed
  one-site provisioning event is retried from the same keyset cursor. This is not a
  complete enterprise multisite certification.
- Deactivation retains the index and stops scheduled work. Uninstall is the
  explicit destructive boundary: it drops all current and recoverable legacy
  FTS tables before deleting operational options. It does not create or delete
  WordPress posts, demo content, or analyzer/upload/release artifacts.

## Search Provider Compatibility

The provider compatibility setting controls only null handoff ownership; it
never permits Language FTS to replace a non-null provider result. The default
mode runs FTS after an earlier provider returns `null`; the stricter mode keeps
any provider-integrated query on core WordPress. Known-provider detection can report
families such as Jetpack Search / Jetpack, SearchWP, Relevanssi, and
ElasticPress from safe activation, option, class, and function signals, but the
current repository does not include live end-to-end proof for those providers on
a real site.

Theme and custom `posts_pre_query` integrations that are not recognized as a
known provider appear in diagnostics as generic hook callback labels. Earlier
providers may hand off with `null` only in the default mode. Same-priority and
later providers stay on core because they could change membership after the FTS
limit. Registered SQL clause/request filters and post-result membership filters
also stay on core. The hook pipeline view lists registered callbacks around
Language FTS without calling them. A separate late observer normally preserves
incoming results; for a plugin-owned unavailable query it restores the empty
fail-closed page after callbacks registered during relational execution run.
It is not used as a workaround for unsafe ownership. If diagnostics are
disabled, the trace is missing, or the final value cannot be safely compared,
final ownership is reported as unavailable instead of guessing.

Request diagnostics are request-local and bounded. They help explain the current
admin or debug-enabled request, but they are not persistent conflict logs,
historical telemetry, or proof that every provider interaction has been
observed.

The public REST search helper is absent by default and must be enabled by an
operator. It uses the same exact set-oriented page as the other production
adapters; broad OR and single broad prefix work must examine every matching
compact posting to rank the exact top page, but cannot create more than
plan/rank/hydrate statements or return completion/posting collections to PHP.
Visitors and callers without
`manage_options` receive only visible `results`, even if they pass `explain=1`;
structured explain diagnostics are operator-only and are filtered to the
visible rows returned in that response.

The plugin deliberately adds no database-backed hot-path counter or response
cache. Public deployments must apply rate limits and caching at the host/CDN.
Fixed input limits reject abusive plans before SQL, but no PHP elapsed-time
guard can interrupt a database statement already executing.

## Content Scope

- Full WP-CLI reindexing and runtime post-save indexing share the same
  `WP_FTS_PostContentExtractor` path.
- The extractor indexes title, content, excerpt, taxonomy terms, selected custom
  fields, field boosts, and bounded product metadata from the worker's attached
  source snapshot. Rendered deltas are legacy component-only behavior; the
  relational worker rejects rendering options before extraction.
- Custom fields must be selected through `custom_fields`, `custom_field_keys`,
  the `wp_fts_index_custom_fields` option, or the `wp_fts_post_custom_fields`
  filter.
- One document may contribute at most 2 MiB of saved title, body, excerpt,
  taxonomy labels, selected metadata keys, and selected metadata values. It may
  select at most 32 custom-field keys, contain at most 512 total canonical
  `wp_postmeta` and taxonomy rows, and no one dependency value may exceed
  256 KiB. Unselected meta keys still count: the common WordPress schema lacks
  the composite `(post_id, meta_key, meta_id)` support needed to avoid scanning
  them while proving the bound. Meta-heavy object types need that explicit
  schema support or a different backend. These are
  fail-closed source limits, not truncation: an invalid generation removes any
  older derived row and is acknowledged as a permanent rejection so it cannot
  pin initial-index readiness.
- A shared worker accepts no more than 8 MiB of raw source, 2,048 dependency
  rows, 512 selected dependency identities, or 256 KiB of selected-key text in
  one batch. Its three core branches are independently capped at 2,049 rows
  from each of the two dependency sources plus 100 post sentinels (4,198 rows).
  Active Polylang and WPML integrations add at most one bounded 101-row branch
  each, for at most five branches and 4,400 transported rows. It releases the
  remaining claimed generations at a document boundary for a later pass. The
  branch count is independent of whether the claim contains one post or 100,
  every indexed source scan has its declared stop, and the complete statement
  never exceeds 32 KiB. Post fields are length-measured before an
  explicit-column read, and
  dependency values are measured before a bounded read, so a 100-item claim
  does not buffer 100 large bodies or an unbounded metadata/taxonomy result on
  a low-memory host. Value projections are byte slices (`BINARY` on
  MySQL/MariaDB and `BLOB` on SQLite) grouped by the next power-of-two bucket.
  Their transport is strictly below twice the measured bytes. A missing row,
  changed key, changed byte length, short projection, or concurrent growth
  defers the whole document generation; it is not indexed from a prefix.
- Before either WordPress HTML processor or the fallback parser runs, one
  streamed preflight limits markup to 20,000 tokens, 256 nested elements,
  16,384 bytes per element tag, 128 attributes per tag, 4,096 bytes per
  complete ordinary attribute, 64 bytes per language attribute, and eight language
  subtags. Limits are fail-closed typed rejections, not HTML truncation.
- A caller-provided HTML processor is also bounded after each provider call:
  40,001 total tokens, 256 active element-state rows below implicit fragment
  roots, 16 KiB per tag, 64 bytes per language value, and 64 bytes per token-type
  name. Tag/language/text output and token-type output each have a 2-MiB
  aggregate envelope. The processor must expose the WordPress 6.6 depth/closer
  event API; otherwise analysis uses the bounded fallback parser. The analyzer
  never requests breadcrumbs: openers push one scalar row, closers pop it, and
  inline paths are persistent request-local nodes. Accepted depth and token
  maxima therefore require linear rather than product time or storage. Its methods still
  execute arbitrary caller code and cannot be interrupted by PHP, but returned
  values are rejected before the plugin trims, normalizes, copies, or stores an
  over-limit result.
- Custom tokenizer, token-normalizer, and stemmer output is limited to one
  4-KiB lexical run. Legacy third-party analyzer arrays stop at 20,000 rows
  (production relational search still stops at 12 alternatives), and their
  term, language, surface, position, and rank scalars are checked before array
  reindexing or query-plan normalization.
- The bounded relational worker rejects dynamic block/shortcode rendering and
  custom render callbacks before extraction. Arbitrary renderer code cannot be
  interrupted or assigned a fixed query/load bound. Save bounded static text in
  `post_content` or a selected custom field instead. Explicit rendering remains
  available only on legacy in-memory/file component paths without the
  production relational guarantee.
- WordPress metadata and taxonomy API mutations enqueue affected posts. Direct
  SQL writes bypass those hooks and require explicit invalidation or reindexing.
- On MySQL/MariaDB, an existing-object mutation installs one durable dirty
  generation before canonical SQL and promotes it afterward. Every request
  advances the same canonical primary-key row; a later token supersedes an
  earlier token atomically, and an earlier completion cannot promote the newer
  generation. This requires no per-job session lock or request-unique fallback
  row. Sequential metadata writes for one post retain one request-level fence
  and promote it at shutdown instead of issuing a query per metadata field.
- Comments, media attachment contents, and complete template-rendered pages are
  not indexed by the default workflow.
- The production relational plugin indexes canonical WordPress posts only.
  Arbitrary component documents are not visible through its `wp_posts`-joined
  search path, and public storage mutations require the shared worker lease.

## Language Detection

The implementation has conservative gap-only language detection for routing
untagged content and queries. It is not statistical language detection. Explicit
options, the batch-preloaded `FTS Language` override, HTML `lang` / `xml:lang`
attributes, and custom resolvers remain authoritative; detector evidence can
only fill gaps before the site locale or analyzer default fallback is used.
The bounded worker snapshots Polylang and WPML assignments with at most one
indexed query per active integration and never invokes either per-post API in
its document loop. The plugin runtime installs no provider-backed document or
query resolver; custom analyzer resolvers are caller-owned extension code.

The detector uses script ranges, distinctive Latin letters, and compact lexical
evidence. Weak generic Latin text stays on the fallback language, so unsupported
or ambiguous content can still land in the wrong language partition.

Current language support is best read by tier:

| Language or partition | What works today | What it does not claim |
| --- | --- | --- |
| Polish (`pl`) | Explicit routing plus the bundled Polish lemmatizer runtime default: the compressed full Polish runtime pack when gzip support is available, falling back to the bundled fixture pack otherwise. `polish_lemma_pack` and `polish_lemmatizer_pack` can replace or disable that default, and `polish_stemming => 'verified'` enables a compact fixture-backed stemmer slice when no valid pack is active. | The raw CLARIN-PL source archive, extracted TSV, and separately generated external PoliMorf pack are not bundled in release archives. |
| English (`en`), Hindi (`hi`), Spanish (`es`), Arabic (`ar`), French (`fr`), Bengali (`bn`), Portuguese (`pt`), Indonesian (`id`), Russian (`ru`), German (`de`), Telugu (`te`), Turkish (`tr`), Italian (`it`), Persian (`fa`), Ukrainian (`uk`), Dutch (`nl`) | Source-backed UniMorph lemma packs are bundled as opt-in gzip-sharded analyzer packs. | Not synonym expansion, phrase search, cross-language merging, or a default-enabled production analyzer path. Built-in stemmers/baselines/no-op behavior remain fallback behavior when packs are not configured. |
| Japanese (`ja`), Korean (`ko`) | Selectable/detectable partitions using deterministic CJK/Hangul fallback tokenization. | No Japanese or Korean runtime lemma pack is committed because the current PHP pipeline lacks a source-backed word segmenter for those languages. |
| Catalan (`ca`), legacy Dutch Porter fallback (`nl`) | Optional Wamania-backed Snowball stemming when Composer dependencies are present and the compliance harness accepts them. | Dutch now has a source-backed UniMorph pack when configured; no broad Wamania language claim is made beyond the allowlist. |
| Chinese (`zh`) | Deterministic CJK fallback n-grams up to 4 characters, plus optional Jieba dictionary segmentation from the curated pinned runtime (or initialized source checkout during development). | Jieba is segmentation only, default-disabled outside the sandbox, and not morphology, synonym expansion, phrase search, broad Simplified/Traditional conversion, or a production custom-dictionary API. |
| Urdu (`ur`) | Arabic-script mark/tatweel normalization plus deterministic suffix baseline for common feminine, masculine, Arabic-loan, and plural-oblique forms. | UniMorph Urdu is license-blocked because `unimorph/urd` has no redistribution license evidence; no generated Urdu pack is bundled. Persian (`fa`) is now its own partition and is not merged into Urdu routing. |
| Generic packs | `lemma_packs_by_lang` / `lemmatizer_packs_by_lang` can enable local manifest-backed, language-matched packs. | Invalid, missing, disabled, or mismatched packs do not stop indexing; they fall back to the built-in analyzer path. Runtime lines are capped at 4 KiB. Only `fixture_only` packs with at most 50,000 rows and 8 MiB of decoded runtime data may use eager unindexed storage, and all distinct eager-eligible fixture manifests in one analyzer share both the 50,000-row and 8-MiB decoded allowances; every other shard requires indexed gzip plus a validated block-index sidecar. Multi-shard packs require complete normalized, strictly ordered, non-overlapping surface ranges so one lookup can select at most one shard. |

Every valid lemma pack is limited to twelve lemmas for one surface across all
shards. Full validation, eager fixture loading, and indexed runtime lookup
enforce the same bound. The source importer retains an exact twelve-candidate surface;
when approved source data contains thirteen or more unique lemmas, it emits one
explicit surface-to-itself row and records the replaced source-pair count. That
is a deterministic ambiguity no-op, not a lexical first-twelve subset. A
manually authored or corrupt runtime containing a thirteenth candidate is still
rejected by validation and every lazy lookup path.

Morphology support must come from verified algorithms, analyzers, or
manifest-backed lemmatizer packs. Hard-coded word-family expansion is not a
supported product path.

Search can route different query terms to different language partitions. Each
term still scores inside one resolved partition, and the searcher does not merge
one term's scores across multiple languages.

## Supported Stemming

Stemming is enabled by default and can be disabled with
`enable_stemming => false`. The built-in stemming path is intentionally narrow:

- Advertised Snowball support is exactly bundled generated Arabic (`ar`),
  English Porter2 (`en`), Spanish (`es`), French (`fr`), Hindi (`hi`),
  Portuguese (`pt`), and Indonesian (`id`), plus optional Wamania-backed
  Catalan (`ca`) and Dutch Porter (`nl`) when Composer dependencies are
  installed, because those are the implementations currently verified by the
  Snowball fixture harness.
- Wamania exposes other language classes, but this branch treats unsupported or
  divergent algorithms as no-ops instead of claiming compliance.
- Polish (`pl`) uses a conservative local suffix stemmer by default. A valid
  opt-in Morfologik/PoliMorf-compatible fixture pack takes precedence over
  `polish_stemming`; otherwise `polish_stemming => 'verified'` can enable a
  compact fixture-backed stemmer slice. Neither path is a full Snowball,
  Stempel, Morfologik, PoliMorf, or dictionary lemmatizer.
- Generic opt-in lemma-pack infrastructure exists through
  `lemma_packs_by_lang` / `lemmatizer_packs_by_lang`. Bundled source-backed
  UniMorph packs exist for `en`, `es`, `fr`, `hi`, `ar`, `bn`, `pt`, `id`,
  `ru`, `de`, `te`, `tr`, `it`, `fa`, `uk`, and `nl`.
  They are enabled automatically only for the admin/Playground sandbox and
  remain default-disabled elsewhere. The old synthetic `bn` contract pack
  remains a fixture-only runtime contract test; it is not product Bengali
  morphology.
- Hindi (`hi`) uses the bundled generated Snowball stemmer verified against the
  official 65,118-line Hindi fixture data. Bengali (`bn`) uses deterministic
  local suffix stemming for common classifier, plural, genitive, dative, and
  case endings when no opt-in pack is configured; the bundled `bn` UniMorph pack
  can provide source-backed lemmatization when enabled. Urdu remains a recall
  baseline, not Snowball-compliant, lemmatizer-backed, or dictionary-backed.
- Arabic (`ar`) and Urdu (`ur`) normalize away Arabic-script combining
  marks/harakat and tatweel; Persian (`fa`) applies the same mark normalization
  before any configured Persian lemma pack. Arabic then uses the bundled
  generated Snowball stemmer verified against the official compressed Arabic
  fixture data; Urdu strips only common feminine, masculine, Arabic-loan, and
  plural-oblique suffixes. Persian text is routed to the Persian partition when
  detector evidence is clear.
- Punjabi (`pa`/`pnb`), Javanese (`jv`), Vietnamese (`vi`), Marathi (`mr`), and
  Tamil (`ta`) were not included in the committed next pack set because this
  repository did not have a practical source-backed analyzer or tokenizer route
  for them during this pass.
- A full CLARIN-PL PoliMorf external pack builder exists for local/offline
  generation. It verifies the approved source artifact, writes the generated
  indexed-gzip runtime pack outside the plugin package, fully validates the
  resulting manifest, and verifies that the pack can be activated with all
  lookup sidecars retained.
  The source archive and extracted TSV are not committed or bundled. Separately
  generated external pack copies remain outside the release package,
  opt-in/default-disabled, and operators must install them externally before
  enabling
  `polish_lemma_pack` or `polish_lemmatizer_pack`.
- Unsupported languages return the original normalized term.
- Chinese (`zh`) continues to use deterministic CJK fallback n-grams up to 4
  characters. Optional Jieba segmentation uses the attested pinned runtime
  dictionary when configured; development may use the exact initialized source
  checkout. Every requested prefix range is read at most once per segmenter
  instance, including empty ranges. Compact membership maps retain all 337,399
  LanguagePipeline-reachable rows across 5,628 Han prefixes (3,013,489 word
  bytes), below the complete 350,000-row/8-MiB cache admission. There is no
  prefix eviction or aggregate wide-run rejection. One prefix may contribute at
  most 5,000 candidates and each dictionary row at most 8 KiB. Source-only
  custom dictionaries are fixture-only and are omitted by the WordPress runtime;
  production custom dictionaries are not currently supported.

See [Snowball compliance](snowball-compliance.md) for the harness and rationale.
See [Polish lemmatizer source-lock pilot](polish-lemmatizer-source-lock.md) for
the pre-implementation gates required before any Stempel or Morfologik-style
Polish pack can be imported.
See [Polish fixture pack](polish-morfologik-fixture-pack.md) for the opt-in
lemmatizer-pack contract slice.
See [Polish verified stemmer](polish-verified-stemmer.md) for the fixture-backed
Polish slice and its provenance boundary.

## Multilingual Analyzer Roadmap

The lightweight detector only routes text. Serious multilingual relevance needs
resource-backed analyzers with fixture gates before they are enabled by default:

- add Snowball-compatible analyzer packs only where the implementation matches
  official input/output fixtures;
- port source-approved non-Polish lemmatizer/analyzer packs through the opt-in
  `lemma_packs_by_lang` runtime and require dictionary fixture parity; Polish
  already has a bundled runtime default, while separately generated external
  PoliMorf packs remain unbundled artifacts;
- keep new non-Polish per-language analyzer resources opt-in until compliance
  fixtures and regression corpora pass in CI;
- keep optional Chinese dictionary tokenization on the source-backed
  `segmenter_packs_by_lang` path instead of presenting fallback n-grams as word
  segmentation.

## CJK Tokenization

CJK script runs use a fallback tokenizer by default. A one-character run is kept
as one token. Longer CJK runs emit character unigrams plus deterministic
overlapping n-grams up to 4 characters. This improves basic retrieval evidence
without external dictionaries, but it does not understand words, compounds, or
language-specific segmentation rules.

Chinese (`zh`) can optionally add Jieba dictionary segmentation. Release ZIPs
contain the verified pinned `dict.txt`, MIT license, and compact attested lookup
under the component runtime path. Source development initializes the pinned
`components/full-text-search/resources/sources/jieba` git submodule with:

```sh
git submodule update --init --recursive components/full-text-search/resources/sources/jieba
```

The attested lookup header binds `jieba/dict.txt` to commit
`67fa2e36e72f69d9134b8a1037b83fbb070b9775`, SHA-256
`7197c3211ddd98962b036cdf40324d1ea2bfaa12bd028e68faa70111a88e12a8`, and byte
size `5071852`. Construction verifies the 329,972-byte lookup rather than
hashing the complete dictionary; each requested source range is digest-checked
when first read. Missing, uninitialized, or mismatched data fails closed to the
default CJK n-grams. The source tree commits the gitlink and compact lookup, not
copied dictionary rows, HMM/POS, IDF, or model files; the release builder stages
only the verified dictionary, license, and lookup rather than the raw checkout.

## Thai Tokenization

The plugin does not currently ship real Thai segmentation or a production
non-space tokenizer adapter. The repository has a metadata-only source-candidate
preflight for a future `thai_dictionary_tcc_v1` adapter, but no dictionary rows,
TCC/TCC+ rules, or tokenizer adapter are committed. Future work requires a
reviewed source lock for the exact dictionary artifact and TCC/TCC+ rule source
before code, importers, bundled data, or support claims are added. See
[Tokenizer source locks](tokenizer-source-locks.md) for the pending source,
license, clean-room, and verification gates.

## Search Features

Current search supports:

- `OR` and `AND` term matching;
- result limits and signed forward/reverse search-after cursors;
- one explicit, resolved, or detected language plan per analyzed occurrence;
- exact final-word prefix ranges without completion truncation;
- current WordPress post type, status, password, dirty-generation, and GMT date
  filtering inside ranking SQL;
- snippets and highlighting from page-bounded extracted text; and
- deterministic precomputed field impact multiplied by global dictionary
  rarity, with an optional query-time recency lift.

Search membership is morphology-aware. Page decoration highlights literal
surface words without re-running the analyzer once per snippet token.

The front-end and wp-admin adapters take over only query shapes whose complete
membership, visibility, projection, ordering, and cursor page can be expressed
by the relational plan. Other valid WordPress shapes—including taxonomy, meta,
author, date, `search_columns`, and numbered admin pages—remain on core search.
After a supported shape is owned, stale readiness or a relational failure fails
closed rather than changing engines mid-request. Malformed or oversized public
adapter input is likewise rejected before it can reach core search.

It does not support:

- phrases or positions;
- facets;
- typo tolerance;
- cross-language result merging;
- query-time synonyms;
- deep offsets, numbered exact pages, or synchronous exact totals.

WordPress still requires integer `WP_Query::found_posts` and `max_num_pages`
properties. On an FTS-owned query those integers are only the current
cursor-page lower bound needed for adjacent navigation; they are not an exact
match count. Check the `wp_fts_total_relation` query variable, which is
`unknown`, and use `wp_fts_next_cursor` / `wp_fts_previous_cursor` instead of
deriving numbered pagination from `found_posts`.
For replaced front-end searches, the plugin maps only immediately adjacent
`get_pagenum_link()` and `paginate_links()` URLs to those cursors. Concrete
non-adjacent links are disabled locally; the conventional `999999999`
`paginate_links()` base sentinel is preserved only until the helper emits each
concrete URL for final validation.

Relational explain/debug diagnostics report bounded plan shape, the selected
AND anchor, prefix-range use, and statement count. They do not run a second
per-result posting or metadata pass.

## Storage And Concurrency

The MySQL backend stores analyzed lexical identities as `kind=0` and one full
normalized source-surface identity as `kind=1` per distinct surface/document.
It does not materialize every proper prefix. Each identity/document pair has one
posting keyed by `(term_id, post_id)`, alongside one bounded result-document row
and one generation-fenced work row. This avoids prefix-row amplification,
whole-posting-blob lost updates, and duplicate metadata JSON.
Prefix planning sums `doc_freq` over the one complete dictionary range without
reading postings or returning completion rows. A multi-group prefix `AND`
compares that range cost with each resolved exact group. An exact anchor uses
candidate/key probes and intersects the prefix postings; a selected prefix
anchor streams its matching postings once and probes the remaining exact groups
by `(post_id,term_id)`. Candidate count is never multiplied by unrelated
per-document postings, but a broad prefix still requires its matching-posting
scan.

Important caveats remain:

- large/common terms and broad surface ranges still produce or scan many
  posting rows; exact broad OR/prefix work is proportional to those matching
  rows even though SQL count and PHP memory are fixed;
- WP-CLI reindex persists one filtered scope and returns without running the
  worker, selecting source rows, or acquiring the writer lease. WP-Cron or an
  explicit `wp fts process-batch` invocation performs later worker passes; each
  pass discovers and materializes at most 100 post IDs by ascending-ID keyset
  and never builds a corpus-sized PHP array or queue statement. Worker and
  optimize commands fail rather than claiming completion when the shared writer
  lease is unavailable.
  `wp fts delete` never writes the index directly: it rejects an eligible
  canonical post and queues one exact reconciliation generation for a missing
  or ineligible post;
- file and in-memory storage are not production concurrency backends;
- the SQLite adapter is validated as a single-request WordPress Playground
  smoke, not as a multi-request production concurrency backend;
- foreground canonical-write liveness requires every web, cron, and WP-CLI
  process sharing a database to see one stable lock-file inode with working
  POSIX `flock()` semantics. A multi-node host with node-local lock directories
  is unsupported; configure an absolute shared `WP_FTS_FOREGROUND_LOCK_DIR` or
  leave search takeover disabled. The validated contract is separate-process
  PHP-FPM/CLI/prefork execution; a threaded SAPI must independently prove that
  its `flock()` implementation isolates concurrent requests;
- while the exclusive owner probe is busy or unavailable, both `guarded` and
  operator-only `fenced` generations remain closed and the scheduler projects
  at most one indexed row from each protected-state arm to the 300-second
  watchdog. Ready/retry work still uses the ordinary fixed candidate windows.
  When the probe is free, workers query the indexed `guarded` state directly;
  they never scan `claim_token`, and `fenced` is absent from both claim and
  scheduling SQL. A `fenced` generation requires its authoritative post hook or
  a quiesced operator reset. This avoids an unsafe timeout assumption, outer
  candidate-filter starvation, and a permanent cron polling loop. A normal
  final owner release brings ready work forward immediately; a blog switch
  deliberately leaves the old site's watchdog in place rather than writing its
  cron event into the new site's options;
- live behavior depends on the site's database isolation level, object cache,
  cron reliability, and hosting limits.

Use one bulk writer at a time until the target environment has been validated,
and check `wp fts status` when a command reports lock contention.

## MySQL Error Handling

The MySQL backend issues `$wpdb` queries directly. It uses transactions around
bounded document batches, a durable resumable schema migration, typed poison
document failures, generation-aware retries/backoff, and plugin-level writer
coordination for cron/manual/WP-CLI indexing paths. The real-database acceptance
runner is the supported-host validation contract; environments outside its
MariaDB/MySQL and resource profiles still require local verification.
