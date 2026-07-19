# Configuration

Settings > Full-Text Search provides the operator-facing Health, Settings,
Sandbox, Indexed content, and Analyzer packs tabs. The Settings tab controls
indexed post types, automatic indexing, front-end and wp-admin Posts search
replacement, search-provider compatibility, result limits, snippets,
highlighting, prefix matching and its minimum length, optional public REST search,
field ranking weights, optional recency ranking boosts, and single-plan language
routing. WordPress runtime indexing, REST/admin
search, the PHP plugin search helper, and WP-CLI use
`WP_FTS_Plugin::runtime_analyzer()`. Analyzer-pack paths are still configured
through the `wp_fts_analyzer_options` option or filter, operational internals
such as schema version and pending queue state are managed by the plugin, and
selected custom fields can be supplied through an option or filters. More
advanced configuration is available to PHP callers that instantiate
`WP_FTS_Analyzer`, `WP_FTS_LanguagePipeline`, `WP_FTS_Searcher`, or
`WP_FTS_Storage_Mysql` directly.

## Foreground Owner Guard

Content pre-hooks hold one shared POSIX file lock through their durable queue
promotion or request-end handoff. Workers use an exclusive nonblocking probe to
authorize the indexed `guarded` queue state without a database lock query. The
`guard:*` token remains an exact ownership/CAS value, not a worker-side search
predicate. Guardless boundaries use the distinct operator-only `fenced` state.
All PHP processes that share the WordPress database must therefore share one
filesystem inode and working `flock()` semantics.

The deterministic default is adjacent to the canonical `FQDB` file for the
supported SQLite drop-ins, or in a hashed private runtime subdirectory below
`WP_CONTENT_DIR/uploads` for MySQL/MariaDB.
Hosts whose web, cron, and WP-CLI users do not share that location must define
an absolute directory before loading the plugin:

```php
define('WP_FTS_FOREGROUND_LOCK_DIR', '/shared/site-private/wp-fts-locks');
```

The default MySQL/MariaDB site directory is requested with mode `0700`; if the
ordinary `uploads` parent does not exist yet, it is created separately with
WordPress's normal shared-content semantics rather than that private mode. A
custom directory must be traversable by every SAPI. One process creates the empty
lock file with the host's normal umask; other processes need only be able to
open and lock that same regular inode. Do not delete, rotate, symlink, or place
the directory on node-local storage in a multi-node deployment. A reused default
runtime child that is a symlink or grants any group/other permissions is rejected
rather than silently weakening the private-directory boundary. Later canonical
boundaries revalidate the held descriptor against the path's device and inode;
if the path was replaced, that request retires the capability and does not
reacquire the replacement as a new recovery authority. An unavailable guard
fails closed: search takeover is disabled, a finite unmarked
operator-recovery `fenced` row is retained, and Health reports the persistent
latch. Its post-SQL hook may promote it to ordinary ready work, but no worker
auto-claims it: a repaired path cannot prove that its guardless owner has exited.
Free scheduling queries omit `fenced`, so it also creates no permanent cron
polling loop. Repair the path, quiesce all site PHP processes, and run
`wp fts reset-index --yes`; generic maintenance cannot clear the latch. There is
no writable-directory fallback chain because different choices in web and CLI
processes would make the liveness proof false. A hostile exclusive holder is
abandoned when the 50-millisecond monotonic retry deadline expires and takes
the same fail-closed path.

Custom-field selection is intentionally finite: one document can select at
most 32 distinct keys and each key is at most 191 bytes. The batch worker never
silently drops an extra key. An over-limit document is excluded fail-closed and
reported by indexing health diagnostics; an aggregate batch overflow is split
at a document boundary and resumed in a later worker pass.

Dependency preload counts every canonical `wp_postmeta` row for a post,
including keys that are not selected, plus its taxonomy rows against the
512-row per-document limit. The common WordPress schema has no
`(post_id, meta_key, meta_id)` index, so filtering selected keys in SQL would
still scan every row while hiding the true work bound. Sites whose individual
objects routinely exceed this limit need explicit composite-index support or a
different search backend; the plugin does not attempt an unbounded scan.

## Search Replacement Compatibility

The Settings tab includes a Search provider compatibility choice for the
front-end and wp-admin Posts search replacement surfaces.

- **Prefer Language FTS** is the default. Eligible searches use Language FTS
  even when an earlier `posts_pre_query` provider returned a non-null result.
- **Keep another search provider's results when it has already answered**
  preserves a non-null incoming `posts_pre_query` value and returns it
  unchanged. If no earlier provider answered, eligible Language FTS replacement
  can still run.

This mode is independent from the public-site and wp-admin replacement
checkboxes. Use the compatibility mode to coexist with another provider on an
enabled surface; use the `wp_fts_replace_frontend_search` or
`wp_fts_replace_admin_post_search` filters to disable a replacement surface
entirely. Request diagnostics include the effective provider compatibility mode
plus a compact known-provider summary, and record whether Language FTS replaced
an earlier provider response or bailed out because coexistence mode kept another
provider's result. They also include a bounded `posts_pre_query` hook pipeline
around the Language FTS replacement priority so operators can see callback
labels before, at, and after Language FTS without executing those callbacks or
including provider result payloads. Check this setting first when another search
plugin, theme filter, or custom search code appears to win or lose on an enabled
replacement surface.

The Health and Settings tabs also show a read-only known-provider advisory for
common search plugins such as Jetpack Search/Jetpack, SearchWP, Relevanssi, and
ElasticPress. Detection is deliberately bounded: it uses normal plugin
activation options, network-active plugin state when WordPress exposes it,
selected provider option flags, and loaded class/function names. It does not
call third-party provider APIs, perform network requests, scan content, or claim
that an end-to-end integration has been certified. When a known provider is
detected, **Prefer Language FTS** keeps Language FTS in charge of eligible
searches, while **Keep another search provider's results when it has already
answered** is the safer coexistence choice when the detected provider should
answer first.

## Word Beginning Prefix Tuning

The Word beginnings setting is the top-level on/off control for prefix matching.
When enabled, exact analyzer and lemma matches still rank before prefix-only
alternatives. Prefix matching applies only to the final source word and uses
one indexed `kind=1` normalized-surface range inside SQL. If the final source
word is filtered from exact analysis, prefix matching is disabled rather than
falling back to an earlier word:

| Setting | Default | Bounds | Effect |
| --- | ---: | ---: | --- |
| `prefix_min_length` | `4` | `2`-`12` | Shorter values allow shorter searched words to expand, broadening matches and potentially adding slower or noisier alternatives. |

The saved minimum applies to front-end search replacement, wp-admin Posts
search replacement, Sandbox searches, and `WP_FTS_Plugin::search()`. An
explicit `prefix_min_length` option passed to the helper overrides the saved
value for that call after the same bounds are applied.

There is intentionally no completion-count setting. Truncating the dictionary
range would make valid matches disappear according to vocabulary order. The
relational backend instead keeps the full range in SQL and never expands its
terms into PHP or into one SQL arm per completion.

Every prefix plan sums `doc_freq` across that one dictionary range without
reading postings or returning completions to PHP. Multi-group `AND` compares
that bounded range cost with the resolved exact groups and anchors the cheapest
logical group. Exact groups use bounded candidate/key probes. A selected prefix
anchor streams only its term-first posting ranges, applies visibility once, and
probes the remaining exact groups by `(post_id,term_id)`. A non-anchor prefix
still intersects the rare exact candidates without scanning unrelated postings
in each document. Exact broad OR/prefix ranking examines all matching compact
postings, so shorter minimums can increase database work even though statement
count and PHP memory remain bounded.

`wp fts search` accepts `--prefix_matching` to enable word-beginning expansion
for that CLI search, plus `--prefix_min_length` / `--prefix-min-length` for the
minimum-length override. Legacy non-relational component backends retain their
own expansion options, but the WordPress plugin's relational backend does not
use them.

## Public REST Search

The Settings tab keeps the anonymous REST surface separate from normal site
search:

| Setting | Default | Effect |
| --- | --- | --- |
| `rest_api_enabled` | off | Registers the public `wp-fts/v1/search` route. When off, the route is absent. |
| `rest_prefix_matching` | off | Allows the route to use exact final-word prefix matching. A REST request cannot enable it or override the minimum length. |

When enabled, the route uses the same exact set-oriented page as the PHP,
front-end, admin, and WP-CLI adapters. It rejects more than 12 logical groups or
12 alternatives per group, and rejects more than 12 alternatives in total before
ranking. It returns at most 50 rows plus one lookahead row, exposes cursor
pagination with an unknown total, and compiles current WordPress visibility into
SQL. See
[Operations](operations.md#public-rest-search) for response and deployment
details.

## Lower-Level Search Budgets

The relational WordPress backend always enforces its fixed 12-group,
12-alternative-per-group, 12-alternative-total, 50-result, and 32-KiB
generated-SQL limits. These are structural containment checks, not semantic candidate caps.
A `request_budget_guard` callback can stop a request between the analyzer and
the bounded database statements by throwing or returning `false`.

`WP_FTS_Searcher::search()` retains `max_query_terms`,
`max_prefix_expansions`, and `max_candidate_rows` only for direct callers using
legacy non-relational storage adapters. They do not change relational result
membership or cause the WordPress plugin to fetch posting lists into PHP.

The File/InMemory component fixtures retain the budgeted-postings compatibility
contract for those tests and experiments. Production MySQL exposes only the
set-oriented search API and fails legacy posting-list reads closed. A production
request therefore cannot turn one of these component limits into a posting list
scan followed by PHP slicing.

## Ranking Field Weights

The Settings tab exposes the index-time boosts used for extracted WordPress post
fields. Higher numbers make matches in that field count more strongly during
ranking:

| Field | Default | What it covers |
| --- | ---: | --- |
| Title | `5.0` | The post title. |
| Main content | `1.0` | The saved post content. |
| Excerpt | `2.0` | The saved post excerpt. |
| Taxonomy terms | `2.0` | Category, tag, and other taxonomy term names. |
| Selected custom fields | `1.0` | Custom fields selected for indexing. |
| Rendered-only content | `1.0` | Block-rendered output not already present in saved content. |

The settings accept whole numbers from `1` through `100`, matching the integer
weighted frequencies stored in postings. Fractional values are rounded,
positive values below `1` are clamped to `1`, and zero or negative values fall
back to that field's default. Exclude a field with the
`wp_fts_post_index_fields` filter rather than a zero weight. These weights are
written into the index, not applied as live query-time overrides. After changing
them, reindex content to make the new ranking weights fully apply to existing
posts. Programmatic indexing can still pass explicit `field_boosts` options to
override the saved plugin settings for that call.
Saving changed ranking weights or indexed post-type scope enqueues one
profile-reconciliation scope in the durable work table. The settings save does
not rewrite content; bounded `wp fts process-batch` runs keyset-expand that
scope and rewrite its post generations. A scoped `wp fts reindex` is still
available when an operator explicitly wants to rebuild a selected corpus.

## Recency Ranking Boost

The Settings tab can give recent posts a small query-time ranking lift. The
saved `recency_boost_strength` default is `0`, which disables the boost and
preserves existing search behavior. `recency_boost_half_life_days` controls how
quickly the lift fades as a post gets older; the default is `30` days.

The boost uses indexed `post_date_gmt` metadata. Changing strength or half-life
does not require reindexing if that date metadata is already present. Missing,
empty, invalid, or unavailable metadata is a no-op for that document or backend.
Search explain diagnostics include the normalized boost settings and, when the
boost is enabled, its fixed reference time. They include no per-document
counters. Recency is part of the same relational ranking statement;
diagnostics do not trigger a candidate scan or another query.

## Languages

Every stored term is namespaced by language. The stored key shape is:

```text
<language>\x1e<term>
```

Language tags are canonicalized from WordPress-style locales and BCP 47-style
input. For example, `en_US` becomes `en-US`, `pl_PL` becomes `pl-PL`, and empty
values fall back to the caller's default language.

Primary document language resolution during `wp fts reindex` follows this
order. This primary language is stored as document metadata and participates in
the content hash used to decide whether unchanged documents can be skipped:

1. Explicit `--lang` or `--language`.
2. The plugin-owned `FTS Language` post override, loaded for the whole worker
   batch with the other selected post metadata.
3. A Polylang assignment, then a WPML assignment, each loaded for the complete
   claimed batch with at most one indexed query per active integration.
4. The default language from `default_lang` or `locale`, then the WordPress site
   locale, then `en`.

The bounded worker deliberately does not call Polylang
`pll_get_post_language()` or the WPML `wpml_post_language_details` filter once
per post. It reads their canonical assignment tables set-wise for at most the
100 claimed IDs. The runtime analyzer installs no document- or query-language
provider callback. Framework-neutral callers may still supply their own
`document_language_resolver` or `query_language_resolver`; any I/O performed by
those callbacks belongs to the caller, not the plugin's bounded SQL contract.

Analyzer-level routing happens after that primary-language metadata decision.
HTML `lang` and `xml:lang` attributes can route individual content segments into
their own language partition. For untagged segments, the conservative detector
may fill gaps by script, distinctive Latin letters, and compact lexical evidence
before falling back to the primary document language.

Query analysis follows the same explicit-first rule. Prefer passing `--lang` on
operational searches when the language is known; otherwise the analyzer may use
conservative detector evidence or a custom term-language resolver to route
individual untagged query terms into the same partitions as untagged indexed
content:

```sh
wp fts search "zamek" --lang=pl-PL
wp fts search "castle" --lang=en-US
```

The detector is not statistical language detection. It only uses script ranges,
distinctive Latin letters, and compact lexical evidence to fill gaps. Explicit
caller options, the preloaded `FTS Language` override, HTML language attributes,
batch-preloaded Polylang/WPML assignments, and custom language resolvers remain
authoritative.

The built-in baseline detector and admin selectors cover English (`en`),
Mandarin/Chinese (`zh`), Hindi (`hi`), Spanish (`es`), Arabic (`ar`), French
(`fr`), Bengali (`bn`), Portuguese (`pt`), Indonesian (`id`), and Urdu (`ur`).
The next selectable/detectable set adds Russian (`ru`), German (`de`), Japanese
(`ja`), Korean (`ko`), Telugu (`te`), Turkish (`tr`), Italian (`it`), Persian
(`fa`), Ukrainian (`uk`), and Dutch (`nl`).

| Language or partition | Routing support | Analyzer tier | Fallback and boundary |
| --- | --- | --- | --- |
| Polish (`pl`) | Explicit routing, detector evidence, multilingual metadata, and HTML scopes. | The WordPress runtime starts with the bundled Polish lemmatizer behavior: the compressed full Polish runtime pack when gzip support is available, otherwise the fixture pack. `polish_lemma_pack` and `polish_lemmatizer_pack` map to the generic pack runtime and can replace or disable that default; `polish_stemming => 'verified'` enables a fixture-backed stemmer slice when no valid pack is active. | The raw CLARIN-PL source archive, extracted TSV, and separately generated external PoliMorf pack are not bundled in release archives. |
| English (`en`), Hindi (`hi`), Spanish (`es`), Arabic (`ar`), French (`fr`), Bengali (`bn`), Portuguese (`pt`), Indonesian (`id`), Russian (`ru`), German (`de`), Telugu (`te`), Turkish (`tr`), Italian (`it`), Persian (`fa`), Ukrainian (`uk`), Dutch (`nl`) | Selectable/detectable language partitions. | Source-backed UniMorph lemma packs are bundled as opt-in gzip-sharded analyzer packs. | Enable bundled packs from Settings > Full-Text Search > Analyzer packs when PHP gzip support is available, or configure pack paths through `lemma_packs_by_lang` / `lemmatizer_packs_by_lang`; built-in Snowball, baseline, or no-op behavior remains the fallback when no pack is configured. |
| Catalan (`ca`), legacy Dutch Porter fallback (`nl`) | Explicit partitions and detector evidence where present. | Optional Wamania-backed Snowball paths when Composer dependencies are installed and compliance checks accept them. | Dutch now has a source-backed UniMorph pack when configured; the Wamania path is only the no-pack fallback. Other Wamania languages stay no-op until verified against the current Snowball fixtures. |
| Chinese (`zh`) | Selectable/detectable CJK partition. | Deterministic fallback CJK tokenization plus optional Jieba dictionary segmentation from the curated pinned runtime (or initialized source checkout during development) through `segmenter_packs_by_lang`. | Jieba is MIT source data, default-disabled outside the sandbox, and is segmentation only. Source-only custom dictionaries are not a production path. Fallback n-grams remain enabled. |
| Japanese (`ja`), Korean (`ko`) | Selectable/detectable CJK/Hangul partitions. | Deterministic fallback n-gram tokenization. | No Japanese or Korean runtime lemma pack is committed because the current PHP pipeline has no source-backed word segmenter for those languages. Pinned UniMorph source submodules are retained for future external-pack work. |
| Urdu (`ur`) | Selectable/detectable partition. | Arabic-script combining mark/harakat and tatweel normalization plus deterministic light suffix baseline for common plural-oblique forms. | UniMorph Urdu is license-blocked, so no generated Urdu pack is bundled. Persian (`fa`) is a separate partition and is not merged into Urdu routing. |
| Generic packs | Available through `lemma_packs_by_lang` / `lemmatizer_packs_by_lang`. | Local manifest-backed packs whose manifest `language` matches the configured key. | Invalid, missing, disabled, or language-mismatched packs are ignored and the built-in fallback path remains available. |

Morphology support must come from verified algorithms, analyzers, or
manifest-backed lemmatizer packs. Do not model product behavior with hard-coded
word families.

The current searcher scores each query term inside one resolved language
partition. It can route different terms to different partitions, but it does not
merge one term's scores across multiple languages.

## Analyzer Defaults

The default analyzer:

- strips non-visible HTML regions such as `script`, `style`, `noscript`,
  `template`, `svg`, `nav`, `aside`, `footer`, and `form`;
- applies the strongest matching ancestor boost, not multiplied boosts;
- boosts `title`, `h1`, `h2`, `h3`, `strong`, `em`, and `b`;
- folds diacritics by default;
- strips Arabic-script combining marks/harakat and tatweel for Arabic (`ar`)
  and Urdu (`ur`) only;
- applies configured source-backed lemma packs before built-in language
  fallbacks;
- applies bundled generated Snowball stemming for English (`en`), Arabic (`ar`),
  Spanish (`es`), French (`fr`), Hindi (`hi`), Portuguese (`pt`), and
  Indonesian (`id`) when no lemma pack is configured;
- applies configured UniMorph-derived lemma packs for Russian (`ru`), German
  (`de`), Telugu (`te`), Turkish (`tr`), Italian (`it`), Persian (`fa`),
  Ukrainian (`uk`), and Dutch (`nl`);
- applies conservative Bengali (`bn`) classifier/plural/genitive/dative/case
  suffix stemming;
- applies conservative Urdu (`ur`) feminine/masculine/Arabic-loan/plural-oblique
  suffix stemming without Arabic/Persian/Urdu letter rewrites;
- drops non-CJK terms shorter than 2 characters;
- rejects stored term keys over 255 bytes;
- tokenizes one-character CJK script runs as-is and longer CJK runs into
  character unigrams plus deterministic overlapping n-grams up to 4 characters;
- can add optional Chinese Jieba segments when the curated pinned runtime is
  available and valid.

The default CJK path is fallback n-gram retrieval, not dictionary word
segmentation. The optional Chinese Jieba adapter uses the release's curated
runtime dictionary or the exact initialized source checkout, verifies its
attested lookup and each range as read, emits deterministic longest-match
segments, and keeps fallback n-grams in the same token stream. If the pinned
runtime is missing or invalid, the adapter is ignored and fallback n-grams are
used. Production source-only custom dictionaries are not supported.
The plugin does not ship a Thai tokenizer, Thai dictionary, TCC/TCC+ rules, or a
production non-space tokenizer adapter. Any future Thai adapter must pass the
[tokenizer source-lock](tokenizer-source-locks.md) gate first.

Programmatic callers can tune these options:

```php
$analyzer = new WP_FTS_Analyzer([
    'default_lang' => 'en-US',
    'skip_ancestors' => ['SCRIPT', 'STYLE', 'NAV', 'ASIDE', 'FOOTER'],
    'boosts' => [
        'TITLE' => 6.0,
        'H1' => 4.0,
        'H2' => 2.5,
        'STRONG' => 1.5,
    ],
    'min_term_len' => 2,
    'max_term_bytes' => 255,
    'fold_diacritics' => true,
]);
```

WordPress runtime indexing, REST/admin search, the PHP plugin search helper, and
WP-CLI use the same plugin runtime analyzer. Reindex after changing analyzer
options so stored terms and document signatures are rebuilt with the new
behavior. The runtime keeps the bundled Polish lemmatizer default described
above, while the admin/Playground sandbox adds a demo-only analyzer layer for
the non-Polish bundled packs described below.

Analyzer behavior participates in document-signature detection. A reindex skips
unchanged content only when the source content, primary language, and
analyzer/index signature still match; stemming or language-pipeline changes
force existing documents to be rewritten.

Bundled analyzer-pack admin controls update stored runtime analyzer-pack
selections and enqueue one durable profile-reconciliation scope when the
effective plugin-owned runtime pack profile changes. Invalid or unknown
submitted languages are ignored, and the save path does not create posts,
drain queues, or write FTS terms. Use Health > Index the next batch now or
`wp fts process-batch` to consume the resulting scope and post generations in
bounded steps.

## Stemmers

Stemming is enabled by default. The pipeline uses:

- bundled generated Snowball English/Porter2 for English (`en` and English
  locale tags), verified against the official `english` fixture data;
- bundled generated Snowball Arabic for Arabic (`ar` and Arabic locale tags),
  verified against the official compressed `arabic` fixture data;
- bundled generated Snowball Spanish for Spanish (`es` and Spanish locale
  tags), verified against the official `spanish` fixture data;
- bundled generated Snowball French for French (`fr` and French locale tags),
  verified against the official `french` fixture data;
- bundled generated Snowball Hindi for Hindi (`hi` and Hindi locale tags),
  verified against the official `hindi` fixture data;
- bundled generated Snowball Portuguese for Portuguese (`pt` and Portuguese
  locale tags), verified against the official `portuguese` fixture data;
- bundled generated Snowball Indonesian for Indonesian (`id` and Indonesian
  locale tags), verified against the official `indonesian` fixture data;
- deterministic Bengali (`bn`) light stemming for common classifier, plural,
  genitive, dative, and case suffixes;
- deterministic Urdu (`ur`) light stemming for common feminine, masculine,
  Arabic-loan, and plural-oblique endings, with Arabic/Persian/Urdu letters
  preserved;
- Snowball through `wamania/php-stemmer` for optional allowlisted languages that
  pass the bundled compliance harness when Composer dependencies are installed:
  Catalan (`ca`) and Dutch Porter (`nl`);
- a small conservative Polish suffix stemmer for `pl` by default;
- an opt-in verified Polish fixture slice with protected ambiguous rows and
  conservative fallback;
- no-op behavior for unsupported languages or missing optional dependencies.

Stemming can be disabled explicitly when exact normalized terms are required:

```php
$analyzer = new WP_FTS_Analyzer([
    'enable_stemming' => false,
]);
```

For Polish, the current mode is intentionally conservative:

```php
$analyzer = new WP_FTS_Analyzer([
    'default_lang' => 'pl',
    'polish_stemming' => 'conservative',
]);
```

The built-in Polish path gives a valid opt-in lemma pack precedence over the
selected `polish_stemming` mode. If no valid pack is configured, the selected
mode runs; unknown mode values normalize to the conservative fallback.

An opt-in Polish fixture pack proves the Morfologik/PoliMorf-compatible
dictionary lemmatizer contract without shipping a full third-party dictionary:

```php
$analyzer = new WP_FTS_Analyzer([
    'default_lang' => 'pl',
    'polish_lemma_pack' => true,
]);
```

The fixture pack maps only its reviewed normalized runtime rows. Ambiguous and
missing forms remain unchanged. If the pack is disabled, missing, or invalid,
the analyzer uses the selected `polish_stemming` mode, which defaults to the
existing conservative Polish suffix stemmer. Validate the fixture with
`php tools/validate-analyzer-pack.php`.

A generated full PoliMorf pack can also be supplied by path after running the
external builder outside the repository:

```sh
php tools/build-polish-polimorf-external-pack.php \
  --source=/tmp/polimorf-20180722.tab.gz \
  --out=/tmp/pl-polimorf-20180722-full
```

```php
$analyzer = new WP_FTS_Analyzer([
    'default_lang' => 'pl',
    'polish_lemma_pack' => '/tmp/pl-polimorf-20180722-full/manifest.json',
]);
```

The alias `polish_lemmatizer_pack` accepts the same boolean, manifest path,
pack directory, or option array shape:

```php
$analyzer = new WP_FTS_Analyzer([
    'default_lang' => 'pl',
    'polish_lemmatizer_pack' => '/tmp/pl-polimorf-20180722-full/manifest.json',
]);
```

The same analyzer-pack runtime can be configured per language for future
source-approved packs:

```php
$analyzer = new WP_FTS_Analyzer([
    'lemma_packs_by_lang' => [
        'bn' => '/srv/wp-fts-packs/bn-approved-lemma-pack/manifest.json',
    ],
]);
```

The alias `lemmatizer_packs_by_lang` accepts the same map shape. Each enabled
pack must validate locally and its manifest `language` must match the configured
language key. A valid pack takes precedence over the built-in baseline or
Snowball path for that language. WordPress fully streams and verifies a pack
before an enable action is stored. Normal runtime construction reads bounded
manifest and lookup metadata without hashing every shard. Immediately before a
candidate shard is used, the runtime verifies the shard and sidecar digests.
Manifests with more than one runtime shard must give every shard normalized
`first_surface` and `last_surface` values in strictly increasing,
non-overlapping order. This structural contract lets lookup binary-select zero
or one shard; an invalid multi-shard manifest is rejected before runtime file
resolution instead of multiplying the per-shard scan allowance. A single-shard
pack may omit those ranges and use the bounded scan fallback.
Stable file-generation attestations are cached; generations with current or
future timestamps are rehashed because PHP timestamps may have one-second
resolution. Candidate attestation or read failures throw instead of silently
storing fallback terms under the healthy pack signature. Missing, structurally
invalid, or language-mismatched packs use the existing fallback analyzer.
WordPress diagnostics hash all declared files and report digest failures as
corrupt. Enabled packs participate in the language-pipeline signature, so
unchanged documents are rewritten when a pack changes.

WordPress runtime configuration uses the same map shape. The plugin starts with
its bundled runtime defaults, merges the `wp_fts_analyzer_options` option, then
applies the `wp_fts_analyzer_options` filter:

```php
update_option(WP_FTS_Plugin::ANALYZER_OPTIONS_OPTION, [
    'lemmatizer_packs_by_lang' => [
        'bn' => '/srv/wp-fts-packs/bn-approved-lemma-pack/manifest.json',
    ],
]);

add_filter(WP_FTS_Plugin::ANALYZER_OPTIONS_FILTER, static function (array $options): array {
    $options['lemma_packs_by_lang']['ur'] = '/srv/wp-fts-packs/ur-approved-lemma-pack/manifest.json';

    return $options;
});
```

Analyzer configuration has a fixed construction envelope, independent of SQL
budgets. At most 32 languages may be configured across aliases. One option
graph may contain at most 2,048 nodes, 64 KiB of scalar/key data, eight nested
array levels, and 256 entries in any array; keys are limited to 128 bytes,
individual scalar values and local paths to 4 KiB, and one pack option to 32
fields. A local manifest is limited to 64 KiB, the same 2,048-node/eight-level
shape, 64 runtime files, 256 lookup blocks per file, and 4,096 lookup blocks per
pack. All packs in one analyzer share a 128-runtime-file/4,096-lookup-block
metadata envelope, and lookup headers stop at 64 KiB. Stored options, filters,
direct component callers, and callback-captured signature state all use these
limits. An over-limit value fails search readiness before FTS SQL or
configured-file probes rather than being truncated, partially enabled, or
expanded during status/profile work.

For bundled UniMorph packs shipped with the plugin, Settings > Full-Text Search
> Analyzer packs provides a bounded checkbox UI that stores exact bundled
manifest paths in `wp_fts_analyzer_options`. Bundled packs affect real site
searches after content is reindexed. Custom pack paths remain option/filter
configuration; the admin UI does not accept arbitrary filesystem paths, install
external data, or create sample content.

`wp fts status` reports the same runtime analyzer-pack posture through a
read-only `language_pack_status` block. Use `wp fts status --format=json` for
automation that needs the current site language, the disabled query-fanout
invariant, matched base runtime language, gzip/runtime-pack availability, active runtime
pack summaries, unsupported or license-blocked language guidance, and the
recommended next action. The status block does not install packs, change
analyzer options, create content, run indexing, or reindex existing content; use
the analyzer-pack controls or the documented option/filter configuration, then
reindex when analyzer behavior changes.

`lemma_packs_by_lang` wins over `lemmatizer_packs_by_lang` for the same
language. The legacy `polish_lemma_pack` / `polish_lemmatizer_pack` aliases map
to `pl` when no explicit Polish entry is present. Explicit `false`, `null`,
`"0"`, `"false"`, `"no"`, or `"off"` disables that configured language entry.
Invalid, missing, and language-mismatched manifests are reported as not active
or corrupt in analyzer-pack status. Missing or structurally invalid packs fall
back to the built-in analyzer path. A candidate digest or indexed-read failure
after construction fails closed so indexing cannot persist different terms
under the configured pack's healthy signature.

The Playground/admin sandbox preserves the bundled Polish runtime default and
also auto-loads all bundled source-backed UniMorph packs when compressed shards
can be read. It also tries the pinned Jieba Chinese segmenter source when the
submodule is initialized and hash-valid. Outside the sandbox, those non-Polish
UniMorph packs and the Jieba segmenter remain opt-in/default-disabled. `zh` is
tokenizer/segmentation-only, `ja` and `ko` use fallback tokenizer lanes with no
committed runtime lemma packs, and the synthetic Bengali pack remains a
default-disabled test fixture, not product data.

### Optional Chinese Jieba Segmenter

Chinese fallback n-grams work without external data. Release ZIPs already carry
the verified pinned dictionary, MIT license, and attested lookup. When running
from a source checkout, initialize the pinned Jieba submodule:

```sh
git submodule update --init --recursive components/full-text-search/resources/sources/jieba
```

The expected source is `https://github.com/fxsjy/jieba` at commit
`67fa2e36e72f69d9134b8a1037b83fbb070b9775`, file `jieba/dict.txt`, SHA-256
`7197c3211ddd98962b036cdf40324d1ea2bfaa12bd028e68faa70111a88e12a8`, byte size
`5071852`, under the upstream MIT license. README evidence for that commit
documents the `word frequency tag` dictionary format, default `dict.txt`
distribution, and `cut_for_search` search-engine segmentation mode.
The compact lookup makes pinned construction hash 329,972 bytes rather than the
5,071,852-byte dictionary. Every requested first-codepoint range is verified and
read at most once per segmenter instance. The complete cache retains all 337,399
LanguagePipeline-reachable rows across 5,628 Han prefixes and 3,013,489 word
bytes, below its 350,000-row/8-MiB bounds. It has no prefix eviction path. One
prefix may contain at most 5,000 candidates and one row at most 8 KiB.

Production runtime does not enable this segmenter by default. Configure it with
the analyzer option or WordPress option/filter:

```php
$analyzer = new WP_FTS_Analyzer([
    'segmenter_packs_by_lang' => [
        'zh' => true,
    ],
]);

update_option(WP_FTS_Plugin::ANALYZER_OPTIONS_OPTION, [
    'segmenter_packs_by_lang' => [
        'zh' => true,
    ],
]);
```

`true` means the curated pinned runtime source, or the exact initialized source
checkout during development. Source-only custom option arrays are supported only
for explicit component fixtures and are omitted by the WordPress runtime, so an
ordinary request never hashes or indexes a local custom dictionary. Production
custom dictionaries are not currently supported; adding them requires a future
offline-built, source-bound attested sidecar contract. The aliases `cjk_segmenter_packs_by_lang`,
`cjk_tokenizer_packs_by_lang`, and `tokenizer_packs_by_lang` are accepted;
`segmenter_packs_by_lang` wins for the same language. Reindex after enabling or
changing the pinned segmenter because its verified source hash participates in
the analyzer/index signature.

### Importing Normalized Lemma TSV Packs

`tools/import-lemma-tsv-pack.php` adapts a source-approved normalized lemma TSV
into the generic analyzer-pack runtime. The source TSV must be UTF-8 and already
normalized for the target language. Each non-comment row uses
`surface<TAB>lemma`; optional third and fourth columns may carry source tags or
notes. The importer sorts and deduplicates rows, writes runtime shards, and
emits `manifest.json` plus `NOTICE.txt` with source, license, attribution, and
provenance metadata. With `--runtime-compression=gzip`, it writes the runtime as
independent concatenated gzip members plus a digest-attested offset sidecar. The
sidecar contains ranges and offsets, not a second dictionary copy. Runtime
lookup inflates one bounded member instead of repeatedly scanning or
materializing a whole gzip shard.

```sh
php tools/import-lemma-tsv-pack.php \
  --source=/path/to/approved-normalized-lemmas.tsv \
  --out=/srv/wp-fts-packs/example-lemma-pack \
  --language=bn \
  --pack-id=bn-approved-lemma-pack \
  --version=2026.06-source-v1 \
  --source-name="Approved source dictionary name" \
  --source-url="https://example.test/source-artifact" \
  --license=CC-BY-4.0 \
  --license-url="https://creativecommons.org/licenses/by/4.0/" \
  --attribution="Required upstream attribution text"
```

Validate the generated pack before configuring it:

```sh
php tools/validate-analyzer-pack.php /srv/wp-fts-packs/example-lemma-pack/manifest.json
```

Existing gzip packs created by an older importer can add the same sidecars and
manifest entries before validation. The retrofit rewrites gzip shards into
independent members and updates their digests, so run it on the controlled pack
copy that will be published:

```sh
php tools/build-lemma-pack-lookup-index.php \
  --manifest=/srv/wp-fts-packs/example-lemma-pack/manifest.json
```

Real dictionary imports require source approval, license compatibility review,
an exact source artifact URL/digest, and required attribution before running the
importer. The repository does not vendor raw upstream source artifacts, and
generated packs stay opt-in and default-disabled.

The repository also includes bundled source-backed UniMorph packs for `en`,
`es`, `fr`, `hi`, `ar`, `bn`, `pt`, `id`, `ru`, `de`, `te`, `tr`, `it`,
`fa`, `uk`, and `nl`; they remain opt-in and default-disabled. The tiny
synthetic `bn` fixture remains only a project-owned runtime contract test, not
product Bengali morphology. `zh` remains tokenizer/segmentation-only, backed by
optional pinned Jieba source instead of copied dictionary rows; `ja` and `ko`
remain fallback tokenizer lanes with source submodules retained for future
external-pack work; and `ur` remains license-blocked with no committed generated
pack.

### Importing CoNLL-U Lemma Packs

`tools/import-conllu-lemma-pack.php` converts source-approved CoNLL-U or
Universal Dependencies style corpora into the normalized lemma TSV contract, then
uses the same analyzer-pack generation path described above. It reads `FORM` and
`LEMMA`, skips CoNLL-U comments, blank lines, multiword token rows, empty-node
rows, placeholder values, and values that do not normalize to one runtime token.

Use this for reviewed treebanks or build artifacts where the exact source,
license, source version, URL, and attribution are known. It is a pack-generation
path, not bundled broad dictionary coverage, and it does not download data or
hard-code word families.

```sh
php tools/import-conllu-lemma-pack.php \
  --source=/path/to/source-approved-treebank \
  --out=/srv/wp-fts-packs/es-ud-lemma-pack \
  --language=es \
  --pack-id=es-ud-lemma-pack \
  --version=2026.06 \
  --source-name="Reviewed Universal Dependencies source" \
  --source-url="https://example.test/source-artifact" \
  --license=CC-BY-SA-4.0 \
  --license-url="https://creativecommons.org/licenses/by-sa/4.0/" \
  --source-version=2026.06 \
  --attribution="Required upstream attribution text"
```

The same path is available in WordPress through WP-CLI:

```sh
wp fts import-conllu-lemma-pack \
  --source=/path/to/source-approved-treebank \
  --language=es \
  --pack-id=es-ud-lemma-pack \
  --version=2026.06 \
  --source-name="Reviewed Universal Dependencies source" \
  --source-url="https://example.test/source-artifact" \
  --license=CC-BY-SA-4.0 \
  --attribution="Required upstream attribution text" \
  --enable
```

`--source` may point to one file or a directory. Directory imports recursively
read stable-sorted `.conllu` files. `--enable` stores the generated manifest in
the runtime analyzer options; reindex existing content after enabling a new pack
so stored index terms use the new lemmatizer.

### Importing UniMorph-Style Lemma Packs

`tools/import-unimorph-lemma-pack.php` converts source-approved inflection
tables shaped like UniMorph rows into the normalized lemma TSV contract, then
uses the same analyzer-pack generation path described above. Each non-comment
input row must be `lemma<TAB>surface<TAB>features`. Comments, blank rows,
placeholder lemma/surface values, and values that do not normalize to one
runtime token are skipped; rows with any other field count are rejected.

Use this for reviewed dictionary-shaped build artifacts where the exact source,
license, source version, URL, and attribution are known. It is a
pack-generation path, not bundled broad dictionary coverage, and it does not
download data or hard-code word families.

```sh
php tools/import-unimorph-lemma-pack.php \
  --source=/path/to/source-approved-unimorph-table \
  --out=/srv/wp-fts-packs/es-unimorph-lemma-pack \
  --language=es \
  --pack-id=es-unimorph-lemma-pack \
  --version=2026.06 \
  --source-name="Reviewed UniMorph source" \
  --source-url="https://example.test/source-artifact" \
  --license=CC-BY-SA-4.0 \
  --license-url="https://creativecommons.org/licenses/by-sa/4.0/" \
  --source-version=2026.06 \
  --attribution="Required upstream attribution text"
```

The same path is available in WordPress through WP-CLI:

```sh
wp fts import-unimorph-lemma-pack \
  --source=/path/to/source-approved-unimorph-table \
  --language=es \
  --pack-id=es-unimorph-lemma-pack \
  --version=2026.06 \
  --source-name="Reviewed UniMorph source" \
  --source-url="https://example.test/source-artifact" \
  --license=CC-BY-SA-4.0 \
  --attribution="Required upstream attribution text" \
  --enable
```

`--source` may point to one file or a directory. Directory imports recursively
read stable-sorted `.txt`, `.tsv`, and `.unimorph` files. `--enable` stores the
generated manifest in the runtime analyzer options; reindex existing content
after enabling a new pack so stored index terms use the new lemmatizer.

Externally generated packs stay opt-in and default-disabled. The full CLARIN-PL
source archive and extracted TSV are not bundled in this repository or plugin
package. Users or build systems that need their own external pack must generate
and install it before pointing `polish_lemma_pack` or
`polish_lemmatizer_pack` at that external manifest.

Enable the verified Polish stemmer slice when fixture-backed stemming is more
important than preserving the exact default suffix-only behavior:

```php
$analyzer = new WP_FTS_Analyzer([
    'default_lang' => 'pl',
    'polish_stemming' => 'verified',
]);
```

The verified mode is still a stemmer. It maps a compact set of reviewed Polish
inflection forms to stems, protects ambiguous rows, and then falls back to the
conservative suffix stemmer for unknown terms. It is separate from a
Morfologik/PoliMorf lemmatizer pack, and it does not vendor a full dictionary.

Disable the Polish suffix stemmer while keeping other analyzer behavior:

```php
$analyzer = new WP_FTS_Analyzer([
    'default_lang' => 'pl',
    'enable_stemming' => true,
    'polish_stemming' => 'none',
]);
```

Use a custom stemmer callback when the built-in adapters are not enough:

```php
$analyzer = new WP_FTS_Analyzer([
    'stemmer' => static function (string $term, string $language): string {
        return $term;
    },
]);
```

Callbacks with a required second parameter receive the canonical language.
One-argument callbacks keep the legacy `($term)` form.

## Stopwords

The analyzer accepts global stopwords and language-specific stopwords. Stopwords
are normalized through the same pipeline before they are compared with indexed
or query terms.

```php
$analyzer = new WP_FTS_Analyzer([
    'default_lang' => 'en',
    'stopwords' => ['the', 'and'],
    'stopwords_by_lang' => [
        'pl' => ['oraz'],
        'de' => ['und'],
    ],
]);
```

The WordPress runtime analyzer does not configure stopwords by default.

## Custom Fields And Content Extraction

Bulk reindexing and runtime post-save indexing both use
`WP_FTS_PostContentExtractor`. The extractor builds weighted fields for title,
static content, excerpt, authoritative batch-preloaded taxonomy terms and
selected custom fields, plus product metadata used by filters, snippets, and
CLI/REST enrichment. The production relational path does not render blocks or
shortcodes.

Custom fields can be selected per post without reading metadata in the filter:

```php
add_filter(WP_FTS_Plugin::POST_INDEX_OPTIONS_FILTER, static function (array $options, object $post): array {
    if ($post->post_type === 'product') {
        $options['custom_fields'] = ['subtitle', 'sku'];
    }

    return $options;
}, 10, 2);
```

The default runtime plugin path also reads the `wp_fts_index_custom_fields`
option when present:

```sh
wp option update wp_fts_index_custom_fields '["subtitle","sku"]' --format=json
wp fts reindex --post_type=post,page --post_status=publish
```

The normalized stored option is part of the accepted index profile. Changing
it schedules one bounded corpus reconciliation before the new profile is
accepted. Per-post behavior supplied by `wp_fts_post_custom_fields` or
`wp_fts_post_index_options` cannot be fingerprinted from a single global
snapshot; when the code or external configuration behind either filter changes,
run `wp fts reindex` or explicitly invalidate every affected post.

Filters can adjust selected fields, metadata, terms, custom field values, and
boosts:

```php
add_filter('wp_fts_post_field_boosts', static function (array $boosts, object $post): array {
    if ($post->post_type === 'product') {
        $boosts['custom_fields'] = 2.0;
    }

    return $boosts;
}, 10, 2);
```

The worker has already attached authoritative `terms` and `custom_fields`
arrays before these filters run. Filter callbacks should inspect those inputs
instead of calling taxonomy, metadata, option, or remote APIs. WordPress filters
are trusted extension code: queries or other work performed inside a callback
are caller-owned and outside the plugin-owned fixed SQL/query-count contract.

Changes made through WordPress's post-meta and taxonomy APIs enqueue affected
posts when a custom-field key selected by `wp_fts_index_custom_fields`,
`wp_fts_post_custom_fields`, or the plugin's `wp_fts_post_index_options` filter,
a term relationship, or a term label changes. Repeated events for one post are
coalesced in the pending queue. Custom fields supplied only to a direct
extractor/indexer call need explicit invalidation. Direct database writes bypass
WordPress hooks and must call
`WP_FTS_Plugin::invalidate_post_content_dependencies()` with the affected post
IDs after clearing the corresponding WordPress object/meta/term caches, or run a
scoped reindex.

Static block text already lives in `post_content` and is indexed without
executing block callbacks. The bounded relational worker rejects
`render_blocks`, `render_shortcodes`, and `render_content_callback` before any
renderer or extractor executes. It records the typed
`dynamic_rendering_not_set_oriented` permanent rejection and removes a stale
derived row instead of retrying arbitrary application code.

After upgrading from a build that rendered blocks by default, run
`wp fts reindex` for every affected post type and status to remove previously
derived dynamic terms from unchanged documents.

Sites that need a computed value should save bounded static text in
`post_content` or in a selected custom field before enqueueing the post. The
legacy in-memory/file component extractor keeps its explicit rendering options
for tests and local tools, but that path has no production relational
query/load guarantee.

Production relational search is deliberately limited to canonical WordPress
posts. Its visibility SQL joins `wp_posts`, so arbitrary non-post document IDs
are not a supported production shape. `WP_FTS_Plugin::storage()` may be used for
reads, but mutations fail unless the plugin's shared worker lease is active.
Programmatic integrations should add post fields through the extractor filters
above, then call `WP_FTS_Plugin::invalidate_post_content_dependencies()` with
at most 1,000 affected post IDs. That API publishes one bounded durable UPSERT;
the set-oriented worker performs the serialized index replacement later.

## Relational Ranking And Search Options

WP-CLI exposes the supported search options:

```sh
wp fts search "query text" --mode=OR --limit=10 --lang=en
wp fts search "query text" --mode=AND --limit=10 --lang=en
wp fts search "query text" --recency_boost=0.3 --recency_boost_half_life_days=30
```

`OR` is the default and returns documents matching any query term. `AND` requires
every query term to be present. `limit` is clamped to the supported 1–50 page
size. The optional recency boost flags apply only to that CLI query and use the
current canonical `wp_posts.post_date_gmt` value inside the ranking statement.

The WordPress plugin uses relational retrieval for every query and rejects
legacy candidate-capping, exact-total, deep-offset, multi-language fanout, and
arbitrary candidate-callback options before SQL. They are not accepted by
`WP_FTS_Plugin::search()`, REST, or WP-CLI, and none of their fields appear in a
relational explain payload.

The separately packaged File/InMemory component fixtures retain a compatibility
API for component tests and non-WordPress experiments. A caller that directly
constructs `WP_FTS_Searcher` with one of those backends can still opt into
document-ID capping with `fast_top_k` or `approximate_top_k` plus
`candidate_cap`/`max_candidates`. That compatibility path can omit a stronger
document beyond the cap. Its payload therefore reports `retrieval_mode`,
`total_is_exact`, `results_may_be_incomplete`, and `candidate_cap`, and its
component-only explain includes `fast_mode.reason`. Those fields describe the
legacy fixture path only; they are not WordPress production search options or
diagnostics.

The `wp_fts_search_results` filter receives only the already ranked, visible,
page-sized rows. It may decorate a row only when it returns the same IDs in the
same order. A return value that removes, inserts, duplicates, or reorders IDs is
ignored as a whole. `doc_id`, score, page membership, ordering, and cursors
therefore remain owned by the relational query. The filter cannot widen or
narrow visibility, refill a page, request another candidate window, or trigger
result-by-result SQL.

## Search Performance Budget Diagnostics

When request diagnostics are visible through Debug Bar or the Health-tab
fallback, completed front-end, wp-admin Posts, and Sandbox search traces include
a Performance budget row. The row reuses the trace's existing `timings_ms`
values and reports whether the total search timing and the `storage/search`
phase were within budget, over budget, disabled, or unavailable.

Request diagnostics are enabled for `manage_options` users, `WP_FTS_DEBUG`,
standard `WP_DEBUG`, or the `wp_fts_debug_enabled` filter. The plugin does not
enable `SAVEQUERIES`; SQL summaries appear only when the environment already
provides `$wpdb->queries`.

The Search hook pipeline diagnostic inspects only WordPress hook registration
state for `posts_pre_query`. It reports bounded callback labels, priorities, and
before/same/after counts as an advisory debugging aid; it does not call
third-party provider APIs, scan content, or certify provider compatibility.

Tune the thresholds in `wp-config.php` or an early plugin/bootstrap file before
the plugin loads:

```php
define('WP_FTS_SEARCH_TOTAL_BUDGET_MS', 100);
define('WP_FTS_SEARCH_STORAGE_BUDGET_MS', 50);
```

Advanced operators can also filter both values:

```php
add_filter('wp_fts_search_performance_budget', static function (array $budgets, array $trace): array {
    $budgets['total_ms'] = 150;
    $budgets['storage_search_ms'] = 75;

    return $budgets;
}, 10, 2);
```

Values are clamped to a bounded positive millisecond range. Set either value to
zero or a negative number to disable that specific budget without reporting a
false over-budget status.

The `k1`/`b` constructor arguments below belong only to the legacy
File/InMemory component fixtures. WordPress production code must use
`WP_FTS_Searcher::for_set_oriented_storage()` and cannot select PHP BM25:

```php
$searcher = new WP_FTS_Searcher(
    $storage,
    $analyzer,
    1.2, // k1: term-frequency saturation
    0.75 // b: document-length normalization
);
```

Snippet generation uses bounded extracted metadata stored at index time. Plain
text is always stored, while bounded field HTML remains available for fallback
text extraction and diagnostics. When highlighting is enabled, snippet tokens
are analyzed before comparison, so a snippet can highlight a different
inflected surface form when the query and candidate token normalize to the same
analyzed key. Returned snippets contain escaped visible text and generated
`<mark>` elements only; source tags and attributes are never returned.

Current search does not support phrases, positions, facets, typo tolerance,
query-time synonyms, deep numeric offsets, or cross-language score merging.
Relational explain reports only the bounded flat plan and recency fields; it
does not run or expose per-result match analysis.

## Storage Prefix

The WP-CLI path uses the active WordPress `$wpdb->prefix`, creating tables such
as `wp_fts_terms` and `wp_fts_documents`. Programmatic callers can pass a custom
prefix:

```php
$storage = new WP_FTS_Storage_Mysql($wpdb, 'custom_');
$storage->create_tables();
```
