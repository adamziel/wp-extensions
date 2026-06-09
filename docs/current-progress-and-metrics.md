# Universal WordPress Importer Progress

Last checked: 2026-06-09

## Summary

| Metric | Current value |
| --- | ---: |
| Test suite passing | 502 tests, 10,127 assertions |
| Implemented document formats | 6 |
| Implemented source/container families | 9 |
| Advertised import families covered by code/tests | 15 |
| Formal tracked format backlog in this repo | 0 |
| Suggested next high-value formats not implemented | 9 |

Test command run:

```sh
cd universal-wordpress-importer && composer test
```

Result:

```text
OK (502 tests, 10127 assertions)
```

## Current Format Status

### Document Files

| Format | Extensions / inputs | Status | Coverage notes |
| --- | --- | --- | --- |
| Markdown | `.md`, `.markdown`, `.mdown`, `.mdx`, `.mdoc`, `.markdoc` | Implemented | Includes front matter, headings, lists, images, links, reference links, unsafe image rejection, streaming, internal link resolution, and docs-flavored Markdown. |
| Docs Markdown dialects | CommonMark, Obsidian, Docusaurus, Astro, Markdoc, MDX wrapper syntax | Implemented | 364 fixture cases: CommonMark 63, Obsidian 60, Docusaurus 60, Astro 60, Markdoc 60, MDX-skip 61. |
| HTML | `.html`, `.htm`, remote HTML pages | Implemented | Scripts/unsafe attributes stripped; clear blocks become native blocks; ambiguous or opaque HTML falls back to Classic/Custom HTML. |
| Plain text | `.txt`, `.text` | Implemented | Converts to paragraph blocks and supports large streamed text documents. |
| EPUB | `.epub` | Implemented | Spine import, NAV/NCX table of contents, internal link resolution, asset extraction, large-book cursoring, unsafe path rejection. |
| PDF | `.pdf` | Implemented, bounded | Native text extraction, structure diagnostics, embedded JPEG/bitmap handling, external text helper, OCR helper, table-ish layout from external text, oversized/unsupported diagnostics. |
| WXR / WordPress XML | `.wxr`, `.xml` | Implemented | Posts, pages, attachments, postmeta, featured media, nav menu items, attachment metadata/parents, deferred relationship handling. |

### Source / Container Inputs

| Input family | Status | Coverage notes |
| --- | --- | --- |
| Local folders/files | Implemented | Mixed local trees import supported files, skip unsupported files, preserve resumability. |
| Browser uploads / dropped folders | Implemented | Admin upload staging, limits, duplicate/path rejection, browser folder tree handling. |
| ZIP archives | Implemented | Archive expansion, nested ZIPs, cursor-based resume, unsafe path rejection, unsupported-only archives complete cleanly. |
| GitHub repositories | Implemented | Sparse checkout through Git plumbing where possible; API/archive fallback; subtree URLs; rate-limit diagnostics. |
| WordPress REST URLs | Implemented | Posts/pages/custom post types, authors/terms/comments/media, pagination, relationship decisions, auth guardrails. |
| WordPress site homepage URLs | Implemented | REST discovery from headers/HTML; feed fallback; single remote HTML fallback. |
| RSS / Atom / RDF feeds | Implemented | Direct feed import and feed-discovered site fallback. |
| OPML feed lists | Implemented | Imports listed feeds with bounded feed count. |
| Media references | Implemented | Local and confirmed first-party remote media are queued through the attachment pipeline. |

## What Is Not Implemented Yet

There is no committed, authoritative backlog that says "these N formats must be
ported next." Based on normal data-liberation expectations, the most likely next
format work is:

| Candidate format | Status |
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

So the practical count is:

- Ported now: 15 advertised import families.
- Formal remaining backlog: 0, because it is not tracked in the repo.
- Suggested next backlog: 9 high-value format families, if we decide to make
  them part of the project scope.

## Format-by-Format Readiness

| Format / input | Ready for demos | Ready for careful users | Main limitation |
| --- | --- | --- | --- |
| Markdown | Yes | Yes | Docs dialect handling is broad but still heuristic, not a full Markdown AST implementation. |
| HTML | Yes | Yes | Ambiguous layouts intentionally preserve safe HTML instead of forcing native blocks. |
| Text | Yes | Yes | Minimal structure by design. |
| EPUB | Yes | Yes | EPUB fidelity depends on spine/manifest quality and supported assets. |
| PDF | Yes | Limited | Bounded first-pass extraction; complex layout/scanned PDFs need helper commands and still may lose fidelity. |
| WXR | Yes | Yes | Relationship mapping can require operator decisions. |
| ZIP / nested ZIP | Yes | Yes | Archive safety and cursoring are covered; imported content still depends on contained formats. |
| GitHub repository | Yes | Yes | Private repos/API limits need configured credentials; commit-SHA-only sources are not the primary path. |
| WordPress REST/site URL | Yes | Yes | Authenticated imports need explicit host-scoped credentials. |
| Feeds / OPML | Yes | Yes | Imports advertised/current feed items, not complete historical crawls. |

## Bottom Line

The importer is in a usable demo/early-user state for the formats it advertises:
Markdown, HTML, text, EPUB, PDF, WXR/XML, ZIPs, GitHub repositories, WordPress
REST/site URLs, feeds, OPML, browser uploads, and media references.

The next meaningful progress is not more status reporting. It is choosing the
next real format family to add, defining acceptance fixtures for it, and making
that backlog explicit.
