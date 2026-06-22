# Limitations

This page documents the current implementation and the remaining production
caveats operators need to account for.

## Runtime Integration

- The plugin exposes WP-CLI commands when `WP_CLI` is active.
- It registers activation, deactivation, uninstall, post-save, status
  transition, trash/delete, WP-Cron queue, REST search, and front-end search
  replacement hooks when WordPress hook APIs are available. In multisite, it
  also registers new-site schema provisioning and site-deletion table discovery
  hooks.
- Front-end main-query search replacement is enabled by default and can be
  disabled with the `wp_fts_replace_frontend_search` filter.
- Settings > Full-Text Search controls indexed post types, automatic indexing,
  front-end and wp-admin Posts search replacement, result output defaults, and
  language fallback. Analyzer pack paths and custom field indexing remain
  option/filter configuration, and operational state such as schema version and
  pending queue state is managed internally.
- Runtime saves are processed through a bounded option-backed queue. This keeps
  hook work small, but it is not a durable external job queue.
- Multisite support is limited to lifecycle schema and cleanup paths: activation
  or repair affects the current site, new sites get empty FTS tables, uninstall
  clears plugin operational options per site, and WordPress site deletion can
  discover those tables. This is not a complete enterprise multisite
  certification.
- Uninstall currently clears operational options and pending queue state, but
  intentionally retains index tables and data. It does not create or delete
  posts, demo content, or analyzer/upload/release artifacts.

## Search Provider Compatibility

The provider compatibility setting is an advisory coexistence control, not a
certification suite for every search plugin. Known-provider detection can report
families such as Jetpack Search / Jetpack, SearchWP, Relevanssi, and
ElasticPress from safe activation, option, class, and function signals, but the
current repository does not include live end-to-end proof for those providers on
a real site.

Theme and custom `posts_pre_query` integrations that are not recognized as a
known provider may only appear in diagnostics as generic hook callback labels.
The hook pipeline view lists registered callbacks around Language FTS without
calling them. For traced front-end and wp-admin Posts searches, a separate
read-only late observer records bounded final ownership after later
`posts_pre_query` callbacks have had a chance to run. It can show whether
Language FTS survived, a later callback changed the FTS result, or coexistence
mode respected an earlier provider result. If diagnostics are disabled, the
trace is missing, or the final value cannot be safely compared, final ownership
is reported as unavailable instead of guessing.

Request diagnostics are request-local and bounded. They help explain the current
admin or debug-enabled request, but they are not persistent conflict logs,
historical telemetry, or proof that every provider interaction has been
observed.

The public REST search helper remains intentionally minimal. Visitors and
callers without `manage_options` receive only visible `results`, even if they
pass `explain=1`; structured explain diagnostics are operator-only and are
filtered to the visible rows returned in that response.

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
| Polish (`pl`) | Explicit routing plus the bundled Polish lemmatizer runtime default: the compressed full Polish runtime pack when gzip support is available, falling back to the bundled fixture pack otherwise. `polish_lemma_pack` and `polish_lemmatizer_pack` can replace or disable that default, and `polish_stemming => 'verified'` enables a compact fixture-backed stemmer slice when no valid pack is active. | The raw CLARIN-PL source archive, extracted TSV, and separately generated external PoliMorf pack are not bundled in release archives. |
| English (`en`), Hindi (`hi`), Spanish (`es`), Arabic (`ar`), French (`fr`), Bengali (`bn`), Portuguese (`pt`), Indonesian (`id`), Russian (`ru`), German (`de`), Telugu (`te`), Turkish (`tr`), Italian (`it`), Persian (`fa`), Ukrainian (`uk`), Dutch (`nl`) | Source-backed UniMorph lemma packs are bundled as opt-in gzip-sharded analyzer packs. | Not synonym expansion, phrase search, cross-language merging, or a default-enabled production analyzer path. Built-in stemmers/baselines/no-op behavior remain fallback behavior when packs are not configured. |
| Japanese (`ja`), Korean (`ko`) | Selectable/detectable partitions using deterministic CJK/Hangul fallback tokenization. | No Japanese or Korean runtime lemma pack is committed because the current PHP pipeline lacks a source-backed word segmenter for those languages. |
| Catalan (`ca`), legacy Dutch Porter fallback (`nl`) | Optional Wamania-backed Snowball stemming when Composer dependencies are present and the compliance harness accepts them. | Dutch now has a source-backed UniMorph pack when configured; no broad Wamania language claim is made beyond the allowlist. |
| Chinese (`zh`) | Deterministic CJK fallback n-grams up to 4 characters, plus optional Jieba dictionary segmentation from the pinned source submodule when configured. | Jieba is segmentation only, default-disabled outside the sandbox, and not morphology, synonym expansion, phrase search, or broad Simplified/Traditional conversion. |
| Urdu (`ur`) | Arabic-script mark/tatweel normalization plus deterministic suffix baseline for common feminine, masculine, Arabic-loan, and plural-oblique forms. | UniMorph Urdu is license-blocked because `unimorph/urd` has no redistribution license evidence; no generated Urdu pack is bundled. Persian (`fa`) is now its own partition and is not merged into Urdu routing. |
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
  runtime pack outside the plugin package, and validates the resulting manifest.
  The source archive and extracted TSV are not committed or bundled. Separately
  generated external pack copies remain outside the release package,
  opt-in/default-disabled, and operators must install them externally before
  enabling
  `polish_lemma_pack` or `polish_lemmatizer_pack`.
- Unsupported languages return the original normalized term.
- Chinese (`zh`) continues to use deterministic CJK fallback n-grams up to 4
  characters. Optional Jieba segmentation reads the pinned submodule source only
  when configured and hash-valid.

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

Chinese (`zh`) can optionally add Jieba dictionary segmentation from the pinned
`indexer/resources/sources/jieba` git submodule. Initialize it with:

```sh
git submodule update --init --recursive indexer/resources/sources/jieba
```

The adapter verifies `jieba/dict.txt` against commit
`67fa2e36e72f69d9134b8a1037b83fbb070b9775`, SHA-256
`7197c3211ddd98962b036cdf40324d1ea2bfaa12bd028e68faa70111a88e12a8`, and byte
size `5071852` before using it. Missing, uninitialized, invalid, or
hash-mismatched data falls back to the default CJK n-grams. The repository
commits only the submodule pointer, not copied Jieba dictionary rows, HMM/POS,
IDF, or model files.

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
- typo tolerance;
- cross-language result merging;
- query-time synonyms;
- pagination cursors.

Explain/debug diagnostics can include bounded field-specific result matches,
term hit counts, configured field weights, and approximate score subtotals when
the document was indexed with field metadata.

## Storage And Concurrency

The MySQL backend stores postings as rows keyed by `(term, doc_id)` instead of
whole per-term blobs. This avoids the previous lost-update pattern where two
writers rewrote the same decoded term payload for different documents.

Important caveats remain:

- large or common terms still produce many posting rows;
- WP-CLI reindex, delete, and optimize use the plugin's shared writer lock and
  skip with an operator warning when another index writer is active, but the
  lock is still option-backed rather than an external distributed lock;
- file and in-memory storage are not production concurrency backends;
- live behavior depends on the site's database isolation level, object cache,
  cron reliability, and hosting limits.

Use one bulk writer at a time until the target environment has been validated,
and check `wp fts status` when a command reports lock contention.

## MySQL Error Handling

The MySQL backend issues `$wpdb` queries directly. It uses transactions around
document updates, `dbDelta()` when available, a stored schema version/repair
path, operation-specific exceptions for failed writes, and plugin-level writer
coordination for cron/manual/WP-CLI indexing paths. It does not yet provide
automatic retries or broad live-site validation across hosting/database
combinations.
