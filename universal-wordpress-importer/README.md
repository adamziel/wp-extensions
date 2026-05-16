# Universal WordPress Importer

[![Try in Playground](https://img.shields.io/badge/Try%20in-WordPress%20Playground-3858e9?style=for-the-badge&logo=wordpress)](https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fraw.githubusercontent.com%2Fadamziel%2Fwp-extensions%2Fmain%2Fblueprints%2Funiversal-wordpress-importer-demo.json)

Universal WordPress Importer imports content trees into WordPress through
durable, resumable sessions. It is designed for Data Liberation-style imports
where a source may be a folder, archive, repository, site export, or a mix of
documents and media.

## Try It In Playground

Open the Playground demo button above. It installs the packaged plugin and
opens `Tools -> Universal Importer`.

Use this bundled sample path as the source:

```text
/wordpress/wp-content/plugins/universal-wordpress-importer/examples/playground-import
```

Start a dry run first if you want to inspect traversal and prepared-document
progress without creating pages. Start a normal import to create draft pages
from the sample Markdown, HTML, text, and nested files.

The sample data lives in
[`examples/playground-import`](examples/playground-import) and includes:

- Markdown pages with links, lists, blockquotes, and a table
- an HTML page with a script that should be stripped
- a plain-text note
- a nested appendix file to exercise tree traversal

## Features

- Imports local paths, browser-dropped folders/files, zip archives, nested
  archives, Markdown, HTML, text, EPUB, WXR, WordPress REST/site URLs,
  RSS/Atom feeds, GitHub repositories, remote HTML pages, PDFs, and media
  references.
- Treats inputs as trees and stores source-item cursors so long traversals can
  resume after bounded ticks.
- Creates WordPress draft pages and infers native blocks when the source
  structure is clear.
- Falls back to Classic or Custom HTML blocks for safe HTML that should not be
  flattened into the wrong native block.
- Strips scripts, event-handler attributes, and unsafe URL schemes before
  draft persistence.
- Detects first-party URL candidates, pauses for confirmation, rewrites
  confirmed source-site URLs to the local public WordPress URL, and preserves
  outside domains.
- Imports local and confirmed first-party remote media through the shared media
  attachment pipeline before persisting pages.
- Provides WP-Cron continuation, an admin keepalive screen, and WP-CLI commands
  for repeatable testing and recovery.
- Stores progress events, pending decisions, locks, idempotency records,
  prepared documents, source items, and media references in custom WordPress
  tables.

## Usage

Install dependencies when running from source:

```bash
composer install
```

Activate the plugin, then open:

```text
Tools -> Universal Importer
```

You can enter a server path, WordPress site URL, REST root, RSS/Atom feed URL,
remote page URL, or GitHub repository URL. You can also choose browser
files/folders or drop a folder onto the upload area.

WP-CLI usage:

```bash
wp plugin activate universal-wordpress-importer
wp universal-importer import ./content-export.zip
wp universal-importer status import_...
wp universal-importer tick import_...
wp universal-importer resume import_...
wp universal-importer abort import_...
```

Confirm source domains up front when you already know them:

```bash
wp universal-importer import ./site-export \
  --confirm-first-party-domains=example.com,www.example.com
```

Resolve discovered domain decisions later:

```bash
wp universal-importer decide import_... confirm-first-party-domains \
  --confirmed-domains=example.com,www.example.com
```

## Examples

Import the bundled Playground sample:

```bash
wp universal-importer import \
  /wordpress/wp-content/plugins/universal-wordpress-importer/examples/playground-import
```

Import a GitHub repository subtree:

```bash
wp universal-importer import \
  https://github.com/example/docs/tree/main/content
```

Import a public WordPress REST site:

```bash
wp universal-importer import https://example.com/wp-json/
```

Import a public WordPress site by its homepage. The importer tries REST first
and can fall back to an advertised feed or the page itself:

```bash
wp universal-importer import https://example.com/
```

Import any RSS or Atom feed:

```bash
wp universal-importer import https://example.com/feed/
```

Import a zip archive:

```bash
wp universal-importer import ./export.zip
```

## Optional PDF Helpers

Native PDF extraction handles simple text streams without external services.
For richer text extraction, configure a local helper:

```bash
export UNIVERSAL_IMPORTER_PDF_TEXT_COMMAND='pdftotext -layout {input} {output}'
export UNIVERSAL_IMPORTER_PDF_TEXT_TIMEOUT=60
```

For scanned or image-only PDFs, configure OCR:

```bash
export UNIVERSAL_IMPORTER_PDF_OCR_COMMAND='ocrmypdf --skip-text --sidecar {output} {input} {scratch}'
export UNIVERSAL_IMPORTER_PDF_OCR_TIMEOUT=60
```

## Limitations

- PDF support is intentionally first-pass and bounded. Native parsing does not
  expand compressed object streams, decode every embedded image codec, preserve
  complex table/vector layout fidelity, or import oversized PDFs. Those cases
  produce diagnostics or can use configured external extraction/OCR helpers.
- Remote authenticated imports require host-scoped environment variables;
  secrets are never stored in importer session tables.
- RSS/Atom imports consume the currently advertised feed items; they do not
  crawl historical feed archives or arbitrary site navigation.
- Relationship mapping can require operator decisions when source users,
  terms, or first-party domains cannot be resolved automatically.
- WP-Cron continuation depends on the site cron runner. Use `wp
  universal-importer tick <session-id>` when cron is disabled.

## Development

Run checks from this directory:

```bash
composer validate --no-check-publish
composer test
composer lint
composer build:release
```

Builds produce:

```text
dist/universal-wordpress-importer-0.1.0.zip
```

Additional docs:

- [Usage](docs/usage.md)
- [Architecture](docs/architecture.md)
- [Recovery model](docs/recovery-model.md)
- [Data Liberation research](docs/data-liberation-research.md)
- [Release packaging](docs/release-packaging.md)
