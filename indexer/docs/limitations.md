# Limitations

This page documents the current branch, not the intended final production
architecture.

## Runtime Integration

- The plugin exposes WP-CLI commands when `WP_CLI` is active.
- It does not currently replace WordPress front-end search.
- It does not register post-save, post-delete, transition, activation, or
  deactivation hooks.
- Activating the plugin does not create tables; the first `wp fts` command does.
- There is no settings screen or persisted configuration.

## Content Scope

- `wp fts reindex` indexes `post_title` and `post_content`.
- Custom fields, taxonomies, excerpts, rendered block output, comments, and
  attachments are not indexed by the WP-CLI reindex path.
- There is no public WordPress filter yet for extending the composed indexing
  HTML.
- Programmatic callers can index custom HTML with `WP_FTS_Indexer`, but that is
  separate from the default CLI workflow.

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
- `limit`;
- one query language;
- BM25 scoring with configurable `k1` and `b` for programmatic callers.

It does not support:

- phrases or positions;
- snippets or highlighting;
- facets;
- field-specific explanations;
- typo tolerance;
- cross-language result merging;
- query-time synonyms;
- pagination cursors.

## Storage And Concurrency

Posting lists are stored as whole binary blobs per term. This has two major
consequences:

- large or common terms are expensive to update because the full posting blob is
  rewritten;
- concurrent index writers can lose updates when they read the same term,
  independently modify the decoded postings, and write the blob back.

Use one writer at a time for reindex, delete, and optimize operations on this
branch.

## MySQL Error Handling

The MySQL backend issues `$wpdb` queries directly. It uses transactions around
document updates and `dbDelta()` when available, but it does not yet provide a
complete schema version lifecycle, lock management, detailed operator-facing
error reporting, or automatic retries for failed writes.
