# Universal WordPress Importer Docs

These docs describe the current importer product surface for operators and
contributors. The plugin is still under active development, but the session
store, continuation runner, admin surface, WP-CLI commands, and first import
pipelines are in place.

## Start Here

- [Usage](usage.md) explains how to run imports from WP-CLI or the WordPress
  admin page, how first-party domain confirmation works, and how to enable
  optional scanned-PDF OCR.
- [Architecture](architecture.md) explains the durable state tables, runner
  pipeline, source traversal, format processors, media/comment/relationship
  persistence, and cache ownership model.
- [Data Liberation research](data-liberation-research.md) records the current
  WordPress Data Liberation sources reviewed for this importer and the
  php-toolkit upstream assessment.
- [Recovery model](recovery-model.md) explains locks, checkpoints,
  idempotency, WP-Cron continuation, browser keepalive, and the hidden
  failure-mode controls used by tests.
- [Release packaging](release-packaging.md) explains how to prepare a
  production plugin zip and WordPress.org SVN release from this Composer-based
  source tree.

## Supported Sources And Formats

The importer treats each input as a tree. A source can be a local file or
directory, a zip file, a GitHub repository URL, a remote URL, or a WordPress
REST site. Archives can contain supported files and nested archives.

Current first-pass processors cover:

- Markdown, text, and sanitized HTML files. HTML import strips script blocks,
  executable event attributes, and script URL attributes before block
  inference.
- WXR exports, including authors, terms, comments, postmeta, attachments,
  attachment parents, captions, descriptions, alt text, and selected remapping.
- EPUB packages, including spine documents, embedded media, internal links, and
  EPUB 2/3 table-of-contents metadata.
- PDFs with bounded native text extraction and optional scanned-PDF OCR.
- Local and first-party remote media references.
- WordPress REST posts, pages, public custom post types, featured media,
  authors, terms, and comments.

Unsupported or incomplete items are not silently ignored. The runner records
durable source-item metadata and progress events so an operator can decide
whether to resume, fix configuration, or abort.
