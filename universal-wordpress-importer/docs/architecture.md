# Architecture

The importer is built around small resumable ticks. Every tick reads durable
state, acquires an expiring lock, advances bounded work, writes progress, and
releases the lock.

## WordPress Integration

`universal-wordpress-importer.php` is the plugin entrypoint. It loads Composer
autoloading, installs the schema on activation, and registers the plugin on
`plugins_loaded`.

`UniversalImporter\Plugin` wires three runtime surfaces:

- `universal_importer_continue_imports` for WP-Cron continuation.
- Tools > Universal Importer for browser-driven imports and keepalive ticks.
- `wp universal-importer` for WP-CLI import control and test automation.

The plugin only creates admin and WP-CLI surfaces in the contexts where they
are needed.

## Durable Tables

Schema version `5` creates importer-owned tables using the current WordPress
database prefix:

- `universal_importer_sessions` stores source, status, checkpoint, progress,
  and lock metadata.
- `universal_importer_idempotency` records durable write keys for posts, media,
  comments, postmeta, attachment metadata, and relationship operations.
- `universal_importer_decisions` stores pending and resolved operator
  decisions.
- `universal_importer_events` stores progress, warnings, and actionable
  diagnostics.
- `universal_importer_source_items` stores queued, processing, discovered,
  imported, skipped, and failed source tree nodes.
- `universal_importer_documents` stores prepared block markup and metadata
  before WordPress draft persistence.
- `universal_importer_media` stores local, embedded, first-party remote, REST,
  and WXR media references before attachment persistence.

Rows use UTC timestamps for stable lock expiry and ordering across site
timezone settings.

## Runner Pipeline

`ImportRunner` coordinates one continuation tick. For each runnable session it:

1. Rejects terminal, paused, failed, and locked sessions with progress events.
2. Moves pending sessions to running.
3. Applies bounded hidden failure controls when tests request them.
4. Advances local filesystem, GitHub, and remote URL discovery.
5. Expands zip and nested zip source items.
6. Processes supported documents into prepared block markup.
7. Infers first-party URL domains and blocks writes until domains are confirmed.
8. Detects, imports, and rewrites media references.
9. Persists prepared documents as WordPress draft posts or pages.
10. Resolves EPUB internal links after drafts exist.
11. Persists WXR postmeta, attachment metadata, attachment parents, comments,
    and resolved relationship mapping decisions.
12. Updates progress, checkpoints, and a `source.discovery_*` event.

Each stage has a bounded per-tick limit so large imports keep making progress
without requiring one long PHP request.

## Source Traversal

Local paths are traversed as trees. Archives are expanded into importer-owned
cache files and re-queued as child source items. GitHub repositories are cached
as zipballs and then use the same archive pipeline. Remote URLs attempt
WordPress REST discovery first and fall back to a single sanitized HTML
document when REST is not available.

The remote WordPress walker discovers alternate REST roots from API `Link`
headers and HTML API link elements. It can discover public custom post type
collections through `wp/v2/types`.

Remote HTTP authentication is handled only at request time by
`WordPressRemoteContentFetcher`. Operators can configure exact allowed hosts
with `UNIVERSAL_IMPORTER_REMOTE_AUTH_HOSTS` and then provide either
`UNIVERSAL_IMPORTER_REMOTE_BEARER_TOKEN` or
`UNIVERSAL_IMPORTER_REMOTE_BASIC_USER` plus
`UNIVERSAL_IMPORTER_REMOTE_BASIC_PASSWORD`. Secrets are read from constants or
environment variables and are not written into importer tables.

## Document Preparation

Document processors do not write posts directly. They stage an
`ImportPreparedDocument` with block markup and normalized metadata.

- Markdown and text become simple heading/paragraph block markup.
- Local HTML, remote HTML, REST rendered content, WXR legacy HTML, and EPUB
  spine HTML share one script-stripping and block-inference path. The shared
  path also removes executable event handlers, `srcdoc`, script URL
  attributes, and legacy executable styles before any blocks are serialized.
  Obvious top-level headings, paragraphs, lists, quotes, pullquotes, tables,
  separators, images, details/summary disclosures, legacy accordions,
  callout/card wrappers, and timeline/step wrappers become native blocks;
  forms, tabbed interfaces, and unknown embeds are preserved as Custom HTML
  when safe; opaque markup falls back to classic blocks.
- WXR uses the `wp-php-toolkit/data-liberation` reader to stream entities and
  resume from cursors.
- EPUB reads the container and OPF manifest, stages spine documents, extracts
  embedded media, rewrites relative internal links to stable placeholders, and
  records navigation metadata.
- PDF performs bounded native text extraction, can call an optional external
  text extractor such as `pdftotext`, and can call an optional local OCR
  command for scanned files. The PDF pass extracts directly embedded
  JPEG/DCTDecode image streams into the managed cache, queues them through the
  shared media pipeline, converts simple layout-aware text tables into
  WordPress table blocks, and stores structured fidelity diagnostics for other
  embedded media, malformed streams, compressed object streams, or complex
  table/vector layout signals so status views can explain what the first-pass
  importer did not preserve. The structure-diagnostic pass stores a durable
  byte cursor before embedded-media or text extraction; oversized PDFs fail
  before helper commands or embedded-media scans run.

Prepared documents are idempotently written as draft posts or pages. If a
write succeeds but the idempotency row is not recorded, the next tick can
recover by importer-owned post metadata.

## Media, Relationships, And Comments

Media references are queued before post persistence so block markup can be
rewritten to local attachment URLs first. Local files, first-party remote URLs,
REST featured media, WXR attachments, and embedded EPUB assets all converge on
the same attachment pipeline.

REST and WXR author and term metadata is staged on prepared documents. When a
local user, taxonomy, or term can be matched or created, the relationship is
applied. Otherwise, the importer records a warning and creates a durable
operator mapping decision that can be resolved later.

REST and WXR comments are staged with source parent ids. The comment persister
uses idempotency and source metadata so retries reuse comments written before a
crash-gap idempotency record is created.

## Cache Ownership

Archive, GitHub, and EPUB extraction caches live under an importer-managed
cache directory. In WordPress runtime this defaults to the uploads directory as
`universal-importer-cache`. Cache metadata is stored on source items and media
references for diagnostics.

The `universal_importer_cleanup_session_cache` action removes importer-owned
cache files for a session.
