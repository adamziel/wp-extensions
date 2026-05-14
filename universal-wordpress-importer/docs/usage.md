# Usage

Use this guide when you want to run an import against a WordPress site and
watch or control its progress. The importer can be driven from WP-CLI or from
Tools > Universal Importer in wp-admin.

## Install

Install Composer dependencies before activating the plugin from a source
checkout:

```bash
composer install
```

Activate the plugin in WordPress. Activation creates the importer tables; admin
and WP-CLI requests also check for schema upgrades.

For a packaged release, install the zip that already contains `vendor/` and
activate it like a normal WordPress plugin.

Build that zip from a clean checkout with:

```bash
composer build:release
```

## Start An Import With WP-CLI

Start with a local path, archive, URL, WordPress site, or GitHub repository URL:

```bash
wp universal-importer import ./content-export.zip
```

The command creates a durable session, records `session.created`, and schedules
an immediate WP-Cron continuation tick. It prints the session id:

```text
Success: Created import session import_... and scheduled continuation.
Current status: pending
```

Check progress with:

```bash
wp universal-importer status import_...
```

Run a continuation tick manually when cron is disabled or when testing:

```bash
wp universal-importer tick import_...
```

Resume a paused or failed session:

```bash
wp universal-importer resume import_...
```

Abort a session that should stop permanently:

```bash
wp universal-importer abort import_...
```

## Confirm First-Party Domains

The importer pauses post persistence when prepared documents contain absolute
URLs that appear to belong to the source site. This prevents accidental
rewrites of outside domains.

If you already know the source domains, confirm them when creating the import:

```bash
wp universal-importer import ./site-export \
  --confirm-first-party-domains=example.com,www.example.com
```

If the importer discovers domains later, `status` shows a pending
`confirm-first-party-domains` decision. Resolve it with:

```bash
wp universal-importer decide import_... confirm-first-party-domains \
  --confirmed-domains=example.com,www.example.com
```

Confirmed exact hosts are rewritten to the local public site URL. Outside
domains and lookalike hosts are left unchanged.

## Use The Admin Page

Open Tools > Universal Importer. Enter a source path or URL, choose files or a
folder from the browser, or drop files/folders onto the upload area. Optionally
enter confirmed first-party domains, and start the import.

In the bundled Playground demo, try this sample source path:

```text
/wordpress/wp-content/plugins/universal-wordpress-importer/examples/playground-import
```

Browser-selected and dropped files are staged into the importer cache as a
managed local directory import, so they continue through the same resumable
runner as server-side paths, archives, and URLs.

The page polls a keepalive endpoint for the active session. Each keepalive runs
one shared runner tick and refreshes status snapshots for source items,
prepared documents, media references, draft posts, relationship warnings,
comments, EPUB table-of-contents summaries, pending decisions, and recent
events.

The admin page can resolve pending decisions and abort sessions. It uses the
same store and runner as WP-CLI and WP-Cron.

## Optional PDF Text Commands

Native PDF extraction handles simple text streams without external services.
For PDFs that need better layout-sensitive text extraction, configure an
operator-provided local text command before falling back to OCR:

```bash
export UNIVERSAL_IMPORTER_PDF_TEXT_COMMAND='pdftotext -layout {input} {output}'
export UNIVERSAL_IMPORTER_PDF_TEXT_TIMEOUT=60
```

Image-only or scanned PDFs can use an operator-provided local OCR command:

```bash
export UNIVERSAL_IMPORTER_PDF_OCR_COMMAND='ocrmypdf --skip-text --sidecar {output} {input} {scratch}'
export UNIVERSAL_IMPORTER_PDF_OCR_TIMEOUT=60
```

Command templates must contain `{input}`. Use `{output}` when the text or OCR
tool writes text to a sidecar file. Use `{scratch}` for tools that require an
output PDF path.

If native extraction, the external text extractor, and OCR cannot produce text,
the source item records actionable `pdf_external_text_status` and
`pdf_ocr_status` diagnostics, plus bounded `pdf_external_text_error` or
`pdf_ocr_error` details when a helper command fails, instead of creating an
empty draft.

PDF imports also inspect embedded media and layout signals. Directly embedded
JPEG image streams are extracted into the importer cache, queued through the
normal media attachment pipeline, and rewritten into generated image blocks
before the draft page is persisted. Large embedded-media scans resume from a
stored PDF byte cursor and image index. Layout-aware external text, such as
`pdftotext -layout`, keeps enough spacing for the importer to turn simple
fixed-width, tab-separated, or pipe-separated table runs into WordPress table
blocks. The first-pass PDF media path extracts at most 10 embedded JPEG assets
per PDF and skips individual embedded media streams larger than 8 MiB; skipped
streams are recorded with `pdf_unsupported_embedded_media_*` metadata and
`media.pdf_asset_unsupported` warning events. Other embedded media encodings
plus complex columns, merged cells, and vector-only tables remain fidelity
diagnostics surfaced by WP-CLI and the admin page.

Malformed or compressed PDF structures are also reported rather than hidden.
Missing `%PDF` headers, missing `%%EOF` markers, unmatched stream markers,
failed `FlateDecode` streams, and PDF object streams that the built-in
first-pass parser cannot expand are persisted as `pdf_structure_*` metadata and
`document.pdf_structure_warning` events, including during incremental native
text scans. This structure-diagnostic pass stores a durable byte cursor before
embedded media scanning or configured PDF helper commands run; oversized PDFs
fail before those phases. Configure an external text command for important PDFs
when these warnings appear.

## GitHub Repository Sources

GitHub repository URLs first try the GitHub tree/blob APIs so `/tree/<ref>/<path>`
subtree URLs can queue only the requested files without downloading the entire
repository archive. If the tree API is unavailable, truncated, or cannot resolve
the ambiguous ref/path split, the importer falls back to the GitHub zipball API
and hands the downloaded package to the archive walker. Public repositories work
without credentials. Set `UNIVERSAL_IMPORTER_GITHUB_TOKEN` as a PHP constant or
environment variable when private repositories or higher API limits are needed;
the token is sent only to `api.github.com` for GitHub tree/blob and zipball API
requests.
GitHub tree/blob API rate limits are stored with retry metadata and retried
after the reported backoff window instead of falling through to an archive
download during the same tick.

If a transient GitHub download or cache error leaves the session blocked with
`github.archive_failed`, fix the network, token, or storage problem and run
`wp universal-importer resume <session-id>`. The next continuation tick retries
the failed GitHub archive download instead of requiring a new import session.

## Authenticated Remote Sources

Remote URL and WordPress REST imports can send credentials to exact hosts that
you explicitly allow. The importer never stores these secrets in import session
rows, source item metadata, prepared documents, or progress events.

For bearer-token protected sources:

```bash
export UNIVERSAL_IMPORTER_REMOTE_AUTH_HOSTS=private.example.com
export UNIVERSAL_IMPORTER_REMOTE_BEARER_TOKEN='token-value'
wp universal-importer import https://private.example.com/wp-json/
```

For WordPress Application Passwords or other Basic-auth protected sources:

```bash
export UNIVERSAL_IMPORTER_REMOTE_AUTH_HOSTS=private.example.com
export UNIVERSAL_IMPORTER_REMOTE_BASIC_USER=editor
export UNIVERSAL_IMPORTER_REMOTE_BASIC_PASSWORD='application password'
wp universal-importer import https://private.example.com/wp-json/
```

`UNIVERSAL_IMPORTER_REMOTE_AUTH_HOSTS` is a comma-separated exact-host
allow-list. Credentials are only attached to requests whose URL host matches
one of those hosts, which prevents accidentally sending private headers to
unrelated remote URLs discovered during traversal. Use the final canonical URL
for authenticated sources; authenticated requests do not follow redirects so
private headers are not forwarded to a different location.

## Development Checks

Run the standard project checks before handing off changes:

```bash
composer test
composer lint
composer validate --strict
```
