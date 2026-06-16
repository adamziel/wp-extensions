# Pure PHP FTS Indexer

[![Try in Playground](https://github.com/WordPress/action-wp-playground-pr-preview/raw/main/assets/playground-preview-button.svg)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/adamziel/wp-extensions/main/indexer/playground/blueprint.json)

Pure PHP FTS Indexer is an experimental WordPress plugin that builds a custom
full-text index for WordPress posts. It indexes post content into derived FTS
tables, keeps those tables current from WordPress post lifecycle hooks, and can
be managed or queried with WP-CLI.

The Playground preview opens the admin-only Settings > Full-Text Search
Sandbox tab. The sandbox prepares demo posts and indexes them automatically,
shows indexed posts with pagination, lets you run language-aware searches, and
indexes new or updated published posts when they are saved. Playground is useful
for trying the workflow quickly; production validation still needs a real
WordPress/MySQL environment.

The plugin does not use MySQL `FULLTEXT`, replaces normal front-end main-query
search and eligible wp-admin Posts list searches with ranked FTS results by
default, and treats the index as rebuildable data derived from WordPress
content.

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

## Architecture

- WordPress activation, post-save/status/delete hooks, cron, REST, and WP-CLI
  wire into `WP_FTS_Indexer`.
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
  storage remains a small local and test backend for non-WordPress contexts.

The index is derived state. Rebuild it after content imports, analyzer changes,
language-routing changes, or environment moves where the FTS tables were not
restored with WordPress content.

## Feature Summary

| Area | Current support |
| --- | --- |
| Indexing | Builds derived `fts_*` tables from WordPress posts, including title, content, excerpt, rendered block deltas, taxonomy terms, selected custom fields, boosts, and bounded result metadata. |
| Lifecycle updates | Activation repairs schema, WP-Cron drains bounded runtime work, post save/status/delete hooks index or tombstone posts, and `wp fts reindex` can rebuild a scoped corpus. |
| Language routing | Terms are stored in language namespaces. Explicit `--lang`, the wp-admin `FTS Language` field, Polylang/WPML metadata, and HTML `lang`/`xml:lang` scopes route content before conservative detector fallback. |
| Search | BM25 scoring supports `OR`/`AND`, `limit`/`offset`, language-aware query analysis, and stored WordPress metadata filters. |
| Snippets | Search can return snippets from bounded extracted metadata, with HTML-aware highlighting based on analyzed query/document keys rather than literal text only. |
| Surfaces | WP-CLI is the main operational surface. The plugin also registers a REST search helper, PHP search helper, front-end main-query replacement, eligible wp-admin Posts list replacement, and admin-only Settings > Full-Text Search tabs used by the Playground preview. |

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
Polish (`pl`), German (`de`), Russian (`ru`), and other explicit partitions can
be routed when callers provide language hints.

| Language or partition | Current analyzer tier | Boundary |
| --- | --- | --- |
| Polish (`pl`) | Strongest path when an opt-in analyzer/lemma pack is valid. `polish_lemma_pack` and `polish_lemmatizer_pack` remain supported aliases; the default fallback is conservative unless a valid pack or verified mode is enabled. | The committed Polish full pack and fixtures remain opt-in/default-disabled outside the sandbox path. |
| English (`en`), Hindi (`hi`), Spanish (`es`), Arabic (`ar`), French (`fr`), Bengali (`bn`), Portuguese (`pt`), Indonesian (`id`) | Bundled source-backed UniMorph analyzer packs are available as opt-in gzip-sharded lemma packs through `lemma_packs_by_lang` / `lemmatizer_packs_by_lang`. | Packs are CC BY-SA 3.0 data, default-disabled, and not synonym, phrase, or cross-language expansion. Built-in Snowball/baseline behavior remains the fallback when no pack is configured. |
| Catalan (`ca`), Dutch Porter (`nl`) | Optional Wamania-backed Snowball support when Composer dependencies are installed and the compliance harness accepts them. | Other Wamania languages are treated as no-ops unless they become verified. |
| Chinese (`zh`) | Deterministic CJK fallback plus optional Jieba dictionary segmentation from the pinned `indexer/resources/sources/jieba` submodule via `segmenter_packs_by_lang`. | Jieba is MIT source data, default-disabled outside the sandbox, and is segmentation only. Fallback n-grams remain enabled for unknown/subword recall. |
| Urdu (`ur`) | Arabic-script mark/tatweel normalization plus deterministic suffix baseline for common plural-oblique forms. | UniMorph Urdu imports technically, but the upstream `unimorph/urd` repository has no license evidence, so no generated Urdu pack is committed. |
| German (`de`), Russian (`ru`), and other explicit partitions | Language namespace/routing support with conservative analysis unless a documented analyzer is available. | Unsupported morphology returns the normalized token unchanged. |
| Generic packs | `lemma_packs_by_lang` / `lemmatizer_packs_by_lang` accept local manifest-backed packs with matching `language` values. | Missing, invalid, disabled, or language-mismatched packs fall back safely. |

Morphology support must come from verified algorithms, analyzers, or
manifest-backed lemmatizer packs. The plugin does not use hard-coded word
families for product behavior.

Importer availability is not the same as pack-backed language support. To audit
top-language readiness, run
`php tools/audit-top-language-lemma-packs.php --pack-root=/path --json --require-pack-backed`.
Languages reported as missing, fixture-only, or license-blocked are not ready to
claim pack-backed quality. Chinese is a tokenizer lane rather than a missing
lemma pack; its optional Jieba source is a git submodule, not copied dictionary
rows in this repository.

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

# Require every analyzed query term to match.
wp fts search "fast durable search" --mode=AND --lang=en --limit=10

# Filter by stored WordPress metadata and include snippets.
wp fts search "fast durable search" --post_type=post,page --post_status=publish --snippet

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
  a plugin archive and how Composer dependencies are handled.
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

This branch is suitable for development and hardening work, not unattended large
production rollout. Validate schema creation, write throughput, batch sizes,
language choices, metadata filters, backups, and restore behavior in the target
environment before using it for production search.

Current caveats:

- front-end search replacement is enabled by default and can be disabled with
  the `wp_fts_replace_frontend_search` filter;
- wp-admin Posts list search replacement is enabled for safe main-list searches
  over indexed supported admin post statuses and can be disabled with the
  `wp_fts_replace_admin_post_search` filter;
- no settings screen;
- custom field indexing must be configured;
- shortcode rendering is opt-in;
- no Thai dictionary segmentation;
- Chinese Jieba dictionary segmentation is optional and requires the pinned
  submodule source to be initialized and hash-valid;
- no honest prefix or phrase search unless an extension supplies that backend.
