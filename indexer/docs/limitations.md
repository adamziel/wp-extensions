# Limitations

This page documents the current implementation and the remaining production
caveats operators need to account for.

## Runtime Integration

- The plugin exposes WP-CLI commands when `WP_CLI` is active.
- It registers activation, deactivation, uninstall, post-save, status
  transition, trash/delete, WP-Cron queue, and REST search hooks when WordPress
  hook APIs are available.
- It does not currently replace WordPress front-end search.
- There is no settings screen for analyzer, search, or extractor configuration;
  operational options such as schema version and pending queue state are managed
  internally.
- Runtime saves are processed through a bounded option-backed queue. This keeps
  hook work small, but it is not a durable external job queue.
- Uninstall currently clears operational options and pending queue state but
  intentionally retains index tables and data.

## Content Scope

- Full WP-CLI reindexing and runtime post-save indexing share the same
  `WP_FTS_PostContentExtractor` path.
- The extractor indexes title, content, excerpt, rendered block deltas, taxonomy
  terms, selected custom fields, field boosts, and bounded product metadata.
- Custom fields must be selected through `custom_fields`, `custom_field_keys`,
  the `wp_fts_index_custom_fields` option, or the `wp_fts_post_custom_fields`
  filter.
- Shortcode rendering is opt-in because shortcode callbacks can run arbitrary
  site code.
- Comments, media attachment contents, and complete template-rendered pages are
  not indexed by the default workflow.
- Programmatic callers can still index custom HTML with `WP_FTS_Indexer` when
  they need a document shape outside the WordPress post extractor.

## Language Detection

The implementation does not do statistical or automatic language detection.
Language comes from explicit options, HTML `lang` attributes, Polylang, WPML, or
the WordPress site locale. If those sources are wrong or missing, terms can be
stored in the wrong language partition.

Search uses one language partition per query. It does not merge scores across
multiple languages.

## Supported Stemming

Stemming is disabled by default. When enabled programmatically:

- Snowball support is intentionally limited to Catalan (`ca`) and Dutch Porter
  (`nl`) because those are the Wamania implementations currently verified by the
  Snowball fixture harness.
- Wamania exposes other language classes, but this branch treats unsupported or
  divergent algorithms as no-ops instead of claiming compliance.
- Polish (`pl`) uses a conservative local suffix stemmer, not a full Snowball or
  dictionary lemmatizer.
- Unsupported languages return the original normalized term.

See [Snowball compliance](snowball-compliance.md) for the harness and rationale.

## CJK Tokenization

CJK script runs use a fallback tokenizer, not dictionary segmentation. A
one-character run is kept as one token. Longer CJK runs become overlapping
bigrams. This improves basic recall without external dictionaries, but it does
not understand words, compounds, or language-specific segmentation rules.

## Search Features

Current search supports:

- `OR` and `AND` term matching;
- `limit` and `offset`;
- one query language;
- post type, status, and GMT date filters when document metadata is present;
- snippets and highlighting from bounded extracted metadata text;
- BM25 scoring with configurable `k1` and `b` for programmatic callers.

It does not support:

- phrases or positions;
- facets;
- field-specific explanations;
- typo tolerance;
- cross-language result merging;
- query-time synonyms;
- pagination cursors.

## Storage And Concurrency

The MySQL backend stores postings as rows keyed by `(term, doc_id)` instead of
whole per-term blobs. This avoids the previous lost-update pattern where two
writers rewrote the same decoded term payload for different documents.

Important caveats remain:

- large or common terms still produce many posting rows;
- bulk reindex, delete, and optimize do not have a distributed lock;
- file and in-memory storage are not production concurrency backends;
- live behavior depends on the site's database isolation level, object cache,
  cron reliability, and hosting limits.

Use one bulk writer at a time until the target environment has been validated.

## MySQL Error Handling

The MySQL backend issues `$wpdb` queries directly. It uses transactions around
document updates, `dbDelta()` when available, a stored schema version/repair
path, and operation-specific exceptions for failed writes. It does not yet
provide lock management, automatic retries, or broad live-site validation across
hosting/database combinations.
