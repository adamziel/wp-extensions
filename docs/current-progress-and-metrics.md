# Current Progress and Metrics

Last checked: 2026-06-09

This report tracks the current state of the two active project areas in this
repository without referring to old task/review lanes.

## Summary

| Project | Current passing gate | Implemented scope | Main remaining gap |
| --- | ---: | --- | --- |
| Universal WordPress Importer | 502 tests, 10,127 assertions | Markdown, HTML, text, EPUB, PDF, WXR/XML, archives, GitHub, WordPress REST/site URLs, feeds, OPML, browser uploads, media references | Pick and commit the next formal format backlog. |
| Pure PHP FTS Indexer | 107/107 named tests, 12,428 checks/scenarios | WordPress full-text indexer with analyzer, language partitions, BM25 search, MySQL-style storage contracts, WP-CLI/REST/lifecycle hooks | Live WordPress/MySQL production validation and richer search features. |

## Universal WordPress Importer

Test command:

```sh
cd universal-wordpress-importer && composer test
```

Result:

```text
OK (502 tests, 10127 assertions)
```

### Import Format Status

| Format / input family | Status | Coverage notes |
| --- | --- | --- |
| Markdown | Implemented | `.md`, `.markdown`, `.mdown`, `.mdx`, `.mdoc`, `.markdoc`; front matter, headings, lists, images, links, unsafe image rejection, streaming, internal links, docs-flavored dialects. |
| Docs Markdown dialects | Implemented | CommonMark, Obsidian, Docusaurus, Astro, Markdoc, and MDX wrapper syntax fixture coverage. |
| HTML | Implemented | Local and remote HTML; unsafe scripts/attributes stripped; clear structures become native blocks; opaque HTML falls back safely. |
| Plain text | Implemented | Paragraph block conversion and large streamed text documents. |
| EPUB | Implemented | Spine import, NAV/NCX contents, internal links, assets, cursoring, unsafe path rejection. |
| PDF | Implemented, bounded | Native text extraction, diagnostics, embedded image handling, optional text/OCR helpers; complex scanned/layout-heavy PDFs remain limited. |
| WXR / WordPress XML | Implemented | Posts, pages, attachments, postmeta, featured media, menus, attachment metadata/parents, deferred relationships. |
| Local folders/files | Implemented | Mixed local trees, unsupported-file skipping, resumability. |
| Browser uploads / dropped folders | Implemented | Upload staging, limits, duplicate/path rejection, folder tree handling. |
| ZIP archives | Implemented | Expansion, nested ZIPs, resume cursors, unsafe path rejection. |
| GitHub repositories | Implemented | Sparse checkout where possible, API/archive fallback, subtree URLs, rate-limit diagnostics. |
| WordPress REST/site URLs | Implemented | REST discovery, posts/pages/CPTs, authors/terms/comments/media, pagination, feed/HTML fallback. |
| RSS / Atom / RDF feeds | Implemented | Direct feed import and feed-discovered site fallback. |
| OPML feed lists | Implemented | Bounded import of listed feeds. |
| Media references | Implemented | Local and confirmed first-party remote media go through the attachment pipeline. |

### Importer Formats Not Yet Implemented

There is no committed authoritative backlog that says these must be next. If we
decide to expand supported input formats, the likely high-value candidates are:

| Candidate | Status |
| --- | --- |
| DOCX | Not implemented |
| ODT | Not implemented |
| RTF | Not implemented |
| AsciiDoc | Not implemented |
| reStructuredText | Not implemented |
| LaTeX | Not implemented |
| CSV / TSV content tables | Not implemented as first-class document imports |
| JSON / YAML structured content | Not implemented as first-class document imports |
| Notion / Confluence exports | Not implemented |

Importer count:

- Implemented advertised import families: 15.
- Formal remaining format backlog: 0 in the repository.
- Suggested next format families: 9, if we choose to make them scope.

## Pure PHP FTS Indexer

Test commands:

```sh
cd indexer && php tests/run.php
cd indexer && php -n tests/run.php
```

Result in both modes:

```text
107/107 named tests passed; failures=0; pending=0; checks/scenarios=12428
```

Snowball fixture harness in this checkout:

```text
0 pass, 37 skip, 0 fail
```

That Snowball result is expected for this checkout because `indexer/vendor/` is
not installed. The adapter only advertises Snowball stemming for the allowlisted
languages that match the official fixtures when the optional Wamania dependency
is installed.

### FTS Component Status

| Component | Status | Coverage notes |
| --- | --- | --- |
| Analyzer / tokenizer | Implemented | HTML-aware visible-text extraction, unsafe-region skipping, element boosts, diacritic folding, invalid UTF-8 tolerance, mixed-script tokenization, CJK fallback bigrams. |
| Language partitioning | Implemented | Terms are stored as language-namespaced keys; document language can come from explicit options, site locale, multilingual plugins, or inline HTML `lang`/`xml:lang`. |
| Language detection | Not implemented | There is no statistical auto-detection. Wrong or missing language metadata can put terms in the wrong partition. |
| Normalization / stemming | Partial by design | Stemming is off by default; optional Snowball path is allowlisted for Catalan and Dutch Porter when dependency data is available; Polish has a conservative suffix stemmer; custom stemmers are supported. |
| Storage backends | Implemented for harness and MySQL contract | In-memory and file backends are covered; MySQL schema/SQL behavior is covered with fakes and contracts. Live MySQL validation remains environment-specific. |
| Indexing lifecycle | Implemented | Activation/schema repair, deactivation, uninstall state cleanup, runtime save/status/delete hooks, bounded queue processing, tombstoning non-searchable/protected posts. |
| Content extraction | Implemented | Title, content, excerpt, rendered block deltas, taxonomy terms, selected custom fields, field boosts, and bounded metadata for filters/snippets. |
| Search | Implemented baseline | BM25 scoring, `OR`/`AND`, limit/offset, one query language, post metadata filters, snippets/highlighting. |
| Search features beyond baseline | Not implemented | No phrase/position search, facets, typo tolerance, query-time synonyms, pagination cursors, field-specific explanations, or cross-language score merging. |
| WordPress surfaces | Implemented with test doubles | WP-CLI reindex/search/delete/optimize, REST search helper, lifecycle hooks, visibility checks, and capability filtering are covered by the PHP harness. |
| Production operations | Partially ready | Docs cover reindex, optimize, schema repair, backup/restore, and sizing notes. Large-site rollout still needs a real WordPress/MySQL validation pass. |

### FTS Quality Coverage

The FTS suite auto-discovers 7 quality modules:

| Quality module | Purpose |
| --- | --- |
| `000-discovery-sentinel.php` | Proves quality-file discovery is active. |
| `analyzer-language-corpus.php` | Analyzer, language, HTML, tokenization, and generated corpus checks. |
| `external-reference-suite.php` | Snowball/BM25/reference-corpus boundaries and optional dependency skips. |
| `harness-metrics.php` | Check counting and minimum-gate behavior. |
| `mysql-wpcli-contracts.php` | MySQL schema, SQL behavior, WP-CLI command contracts. |
| `real-integration-harness-contracts.php` | Documents and safely skips real WordPress/MySQL integration when not configured. |
| `storage-search-properties.php` | Storage parity, BM25 properties, incremental-vs-full rebuild convergence. |

### FTS Remaining Work

| Gap | Why it matters |
| --- | --- |
| Real WordPress/MySQL validation | The current suite uses strong fakes/contracts, but production rollout depends on actual DB isolation, object cache, cron reliability, and hosting limits. |
| Settings/admin UI | There is no settings screen for analyzer/search/extractor configuration. |
| Front-end search integration | The plugin does not replace WordPress core front-end search automatically. |
| Richer search semantics | Phrase/position search, typo tolerance, facets, synonyms, cursors, and cross-language merging are not present. |
| Broader language morphology | Current built-in stemming is intentionally conservative; serious multilingual relevance needs more verified analyzers/lemmatizers. |
| Large-site performance proof | The docs include sizing cautions, but not a final production-scale benchmark against a live large WordPress/MySQL site. |

## Bottom Line

The importer is usable for demos and careful early users across its advertised
format families.

The FTS indexer has a solid pure-PHP quality harness and a functional WordPress
plugin shape, but should still be treated as experimental until it gets live
WordPress/MySQL validation and a deliberate decision on which advanced search
features are in scope.
