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

The implementation has conservative gap-only language detection for routing
untagged content and queries. It is not statistical language detection. Explicit
options, HTML `lang` / `xml:lang` attributes, Polylang, WPML, and custom
resolvers remain authoritative; detector evidence can only fill gaps before the
site locale or analyzer default fallback is used.

The detector uses script ranges, distinctive Latin letters, and compact lexical
evidence. Weak generic Latin text stays on the fallback language, so unsupported
or ambiguous content can still land in the wrong language partition.

The baseline routed set covers English (`en`), Mandarin/Chinese (`zh`), Hindi
(`hi`), Spanish (`es`), Arabic (`ar`), French (`fr`), Bengali (`bn`),
Portuguese (`pt`), Indonesian (`id`), and Urdu (`ur`), with existing Polish
(`pl`), German (`de`), and Russian (`ru`) routing kept available where present.
This support is selectable/detectable language partitioning plus a small
baseline analyzer improvement for selected languages. Spanish, French,
Portuguese, and Indonesian have deterministic suffix/affix stemming rules;
Arabic and Urdu strip Arabic-script marks and tatweel in their own partitions.
Arabic additionally has a narrow article/clitic/suffix light stemmer, and Urdu
strips common plural-oblique suffixes. These are not full morphology,
dictionary segmentation, or hard-coded word-family expansion.

Search can route different query terms to different language partitions. Each
term still scores inside one resolved partition, and the searcher does not merge
one term's scores across multiple languages.

## Supported Stemming

Stemming is enabled by default and can be disabled with
`enable_stemming => false`. The built-in stemming path is intentionally narrow:

- Advertised Snowball support is exactly Catalan (`ca`), bundled generated
  English Porter2 (`en`), and Dutch Porter (`nl`), because those are the
  implementations currently verified by the Snowball fixture harness.
- Wamania exposes other language classes, but this branch treats unsupported or
  divergent algorithms as no-ops instead of claiming compliance.
- Polish (`pl`) uses a conservative local suffix stemmer by default. A valid
  opt-in Morfologik/PoliMorf-compatible fixture pack takes precedence over
  `polish_stemming`; otherwise `polish_stemming => 'verified'` can enable a
  compact fixture-backed stemmer slice. Neither path is a full Snowball,
  Stempel, Morfologik, PoliMorf, or dictionary lemmatizer.
- Spanish (`es`), French (`fr`), Portuguese (`pt`), and Indonesian (`id`) use a
  deterministic local baseline stemmer for common suffix/affix forms. This is a
  recall baseline, not a Snowball-compliant or dictionary-backed analyzer.
- Arabic (`ar`) and Urdu (`ur`) normalize away Arabic-script combining
  marks/harakat and tatweel. Arabic strips only a narrow set of common
  article/clitic prefixes and suffixes; Urdu strips only common plural-oblique
  suffixes. Letters are preserved across Arabic/Persian/Urdu families, and
  Persian-like text is not merged into Urdu routing.
- A full CLARIN-PL PoliMorf external pack builder exists for local/offline
  generation. It verifies the approved source artifact, writes the generated
  runtime pack outside the plugin package, and validates the resulting manifest.
  The source archive, extracted TSV, and generated third-party runtime shards
  are not committed or bundled. The pack remains opt-in and default-disabled,
  and operators must install it externally before enabling
  `polish_lemma_pack` or `polish_lemmatizer_pack`.
- Unsupported languages return the original normalized term.
- Chinese (`zh`) continues to use CJK fallback n-grams, and Hindi (`hi`) and
  Bengali (`bn`) continue to rely on current tokenization and normalization
  without stemming, lemmatization, or dictionary segmentation.

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
- port Polish Stempel/Morfologik-style lemmatization behind the existing
  `stemmer` / `stemmers_by_lang` seam and require dictionary fixture parity;
  the bundled Polish fixture pack is only the committed contract slice, while
  the full PoliMorf pack remains an unbundled external generated artifact;
- keep per-language analyzer resources opt-in until compliance fixtures and
  regression corpora pass in CI;
- add a CJK dictionary tokenizer through the existing `cjk_tokenizer` seam
  instead of expanding fallback bigrams into claimed word segmentation.

## CJK Tokenization

CJK script runs use a fallback tokenizer, not dictionary segmentation. A
one-character run is kept as one token. Longer CJK runs become overlapping
bigrams. This improves basic recall without external dictionaries, but it does
not understand words, compounds, or language-specific segmentation rules.

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
- `limit` and `offset`;
- per-term language partitions from explicit query language, resolver, or
  detector evidence;
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
