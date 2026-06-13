# Pure PHP FTS Indexer

[![Try in Playground](https://github.com/WordPress/action-wp-playground-pr-preview/raw/main/assets/playground-preview-button.svg)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/adamziel/wp-extensions/main/indexer/playground/blueprint.json)

Pure PHP FTS Indexer is an experimental WordPress plugin that builds a custom
full-text index for WordPress posts. It indexes post content into derived FTS
tables, keeps those tables current from WordPress post lifecycle hooks, and can
be managed or queried with WP-CLI.

The Playground preview opens the admin-only Tools > FTS Sandbox page. The
sandbox prepares demo posts and indexes them automatically, shows indexed posts
with pagination, lets you run language-aware searches, and indexes new or
updated published posts when they are saved. Playground is useful for trying the
workflow quickly; production validation still needs a real WordPress/MySQL
environment.

The plugin does not use MySQL `FULLTEXT`, does not replace WordPress front-end
search automatically, and treats the index as rebuildable data derived from
WordPress content.

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
runtime queue processor. Run a first reindex to backfill existing published
content:

```sh
wp fts reindex --post_type=post,page --post_status=publish --batch_size=200
```

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

## Language And Morphology

Language routing is explicit-first. Use `wp fts reindex --lang=...` or
`wp fts search --lang=...` when you know the language. In wp-admin, the `FTS
Language` post field can pin indexing for a post. Polylang/WPML metadata and
HTML `lang`/`xml:lang` scopes are also honored, with HTML scopes able to route
individual content segments.

Automatic detection is conservative gap filling, not statistical language
detection. It uses script ranges, distinctive Latin letters, and compact lexical
evidence only when stronger language signals are absent. Unsupported or
ambiguous languages fall back conservatively instead of guessing aggressively.

The baseline selectable and detectable routing set covers the top-10 spoken
language partitions requested for this branch: English (`en`), Mandarin/Chinese
(`zh`), Hindi (`hi`), Spanish (`es`), Arabic (`ar`), French (`fr`), Bengali
(`bn`), Portuguese (`pt`), Indonesian (`id`), and Urdu (`ur`). Polish (`pl`),
German (`de`), and Russian (`ru`) remain available for explicit routing and
existing detector support where present.

The default pipeline includes bundled Snowball/Porter2 stemming for English.
Catalan and Dutch can use the optional Wamania-backed Snowball stemmers when
Composer dependencies are present and the compliance harness accepts them.
Spanish, French, Portuguese, and Indonesian use deterministic local baseline
stemming for common suffix or affix forms so simple inflection searches can
match without a dictionary pack. Hindi strips only common plural/oblique
suffixes, and Bengali strips only common classifier, plural, and case suffixes.
Arabic and Urdu strip Arabic-script combining marks/harakat and tatweel inside
their own language partitions. Arabic also uses a narrow light stemmer for
common article/clitic prefixes and suffixes; Urdu strips only common
plural-oblique endings. These rules do not rewrite letters across Arabic,
Persian, or Urdu, and Persian-like text is not merged into Urdu routing. Polish
morphology uses configured lemmatizer/analyzer packs where available; it is not
driven by hard-coded word families. Missing packs, unsupported languages,
baseline languages without verified morphology, and ambiguous forms keep
conservative behavior. Chinese uses fallback CJK n-grams; none of these paths
claim dictionary segmentation or morphology.

The analyzer also provides CJK fallback tokenization with one-character runs
kept as-is and longer runs emitted as character unigrams plus overlapping
bigrams. The plugin does not currently ship Thai or CJK dictionary
segmentation.

## Snippets And Highlighting

Snippets come from bounded plain-text metadata extracted during indexing, not
from live post rendering at search time. This keeps result hydration predictable
and avoids storing unbounded source text.

When highlighting is enabled, the highlighter compares snippet tokens through
the same analyzer path used for the query. That means a result can highlight the
matched document surface form even when the query form and document form differ
through stemming or lemmatizer equivalence.

## Common Commands

```sh
# Index published posts using site, post, or multilingual-plugin language hints.
wp fts reindex

# Index posts and pages into an explicit language partition.
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
  schema required before analyzer or lemmatizer data imports.
- [Tokenizer source locks](docs/tokenizer-source-locks.md) documents the
  pre-coding gate for any future Thai TCC/dictionary tokenizer. The current
  plugin does not ship real Thai or CJK word segmentation.
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

- no automatic front-end search replacement;
- no settings screen;
- custom field indexing must be configured;
- shortcode rendering is opt-in;
- no Thai or CJK dictionary segmentation;
- no honest prefix or phrase search unless an extension supplies that backend.
