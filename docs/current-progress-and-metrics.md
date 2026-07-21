# Current Progress and Metrics

Last checked for importer metrics: 2026-06-09

This report tracks current importer progress. Full-text search architecture,
acceptance criteria, and operational results live under `indexer/`.

## Summary

| Project | Current passing gate | Implemented scope | Main remaining gap |
| --- | ---: | --- | --- |
| Universal WordPress Importer | 502 tests, 10,127 assertions | Markdown, HTML, text, EPUB, PDF, WXR/XML, archives, GitHub, WordPress REST/site URLs, feeds, OPML, browser uploads, media references | Pick and commit the next formal format backlog. |

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

## Bottom Line

The importer is usable for demos and careful early users across its advertised
format families.
