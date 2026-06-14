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

Current language support is best read by tier:

| Language or partition | What works today | What it does not claim |
| --- | --- | --- |
| Polish (`pl`) | Explicit routing plus the strongest morphology path when a valid opt-in analyzer/lemma pack is configured. `polish_lemma_pack` and `polish_lemmatizer_pack` remain supported aliases, and `polish_stemming => 'verified'` enables a compact fixture-backed stemmer slice. | Default fallback remains conservative when no valid pack or verified mode is enabled; bundled packs remain opt-in/default-disabled outside the sandbox path. |
| English (`en`), Hindi (`hi`), Spanish (`es`), Arabic (`ar`), French (`fr`), Bengali (`bn`), Portuguese (`pt`), Indonesian (`id`) | Source-backed UniMorph lemma packs are bundled as opt-in gzip-sharded analyzer packs. | Not synonym expansion, phrase search, cross-language merging, or a default-enabled analyzer path. Built-in stemmers/baselines remain fallback behavior when packs are not configured. |
| Catalan (`ca`), Dutch Porter (`nl`) | Optional Wamania-backed Snowball stemming when Composer dependencies are present and the compliance harness accepts them. | No broad Wamania language claim beyond the allowlist. |
| Chinese (`zh`) | Deterministic CJK fallback n-grams up to 4 characters. | No dictionary segmentation, word morphology, or bundled CJK lexical pack. |
| Urdu (`ur`) | Arabic-script mark/tatweel normalization plus deterministic suffix baseline for common feminine, masculine, Arabic-loan, and plural-oblique forms. | UniMorph Urdu is license-blocked because `unimorph/urd` has no redistribution license evidence; no generated Urdu pack is bundled. Persian-like text is not merged into Urdu routing. |
| German (`de`), Russian (`ru`), other explicit partitions | Language namespace/routing support with conservative normalized tokens unless a documented analyzer exists. | No unverified morphology claim. |
| Generic packs | `lemma_packs_by_lang` / `lemmatizer_packs_by_lang` can enable local manifest-backed, language-matched packs. | Invalid, missing, disabled, or mismatched packs do not stop indexing; they fall back to the built-in analyzer path. |

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
  UniMorph packs exist for `en`, `es`, `fr`, `hi`, `ar`, `bn`, `pt`, and `id`.
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
  marks/harakat and tatweel. Arabic then uses the bundled generated Snowball
  stemmer verified against the official compressed Arabic fixture data; Urdu
  strips only common feminine, masculine, Arabic-loan, and plural-oblique
  suffixes. Persian-like text is not merged into Urdu routing.
- A full CLARIN-PL PoliMorf external pack builder exists for local/offline
  generation. It verifies the approved source artifact, writes the generated
  runtime pack outside the plugin package, and validates the resulting manifest.
  The source archive, extracted TSV, and generated third-party runtime shards
  are not committed or bundled. The pack remains opt-in and default-disabled,
  and operators must install it externally before enabling
  `polish_lemma_pack` or `polish_lemmatizer_pack`.
- Unsupported languages return the original normalized term.
- Chinese (`zh`) continues to use deterministic CJK fallback n-grams up to 4
  characters without dictionary segmentation.

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
- port source-approved lemmatizer/analyzer packs through the opt-in
  `lemma_packs_by_lang` runtime and require dictionary fixture parity; the
  bundled Polish fixture pack is only the committed contract slice, while the
  full PoliMorf pack remains an unbundled external generated artifact;
- keep per-language analyzer resources opt-in until compliance fixtures and
  regression corpora pass in CI;
- add a CJK dictionary tokenizer through the existing `cjk_tokenizer` seam
  instead of presenting fallback n-grams as word segmentation.

## CJK Tokenization

CJK script runs use a fallback tokenizer, not dictionary segmentation. A
one-character run is kept as one token. Longer CJK runs emit character unigrams
plus deterministic overlapping n-grams up to 4 characters. This improves basic
retrieval evidence without external dictionaries, but it does not understand
words, compounds, or language-specific segmentation rules.

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

Highlighting is analyzer-aware. A matched snippet can highlight a different
inflected surface form when the query term and candidate token normalize to the
same analyzed key.

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
