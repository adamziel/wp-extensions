=== Universal WordPress Importer ===
Contributors: wordpress
Tags: import, wxr, markdown, epub, pdf, data-liberation
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.2.24
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Resumable importer for document trees, archives, remote WordPress sites, GitHub repositories, and media-rich exports.

== Description ==

Universal WordPress Importer is a resumable importer for content trees. It can accept local files and folders, zip archives, GitHub repository URLs, remote URLs, WordPress REST sites, WXR exports, Markdown, HTML, text, EPUB, PDF, and media references.

The importer stores durable progress in WordPress tables, advances work in bounded continuation ticks, and can resume after PHP timeouts, memory pressure, process crashes, duplicate cron events, and browser keepalive requests.

Current functionality includes:

* Durable import sessions, locks, checkpoints, idempotency records, decisions, source items, prepared documents, media references, and progress events.
* WP-CLI commands for import creation, status, resume, abort, decision resolution, and manual continuation ticks.
* Tools > Universal Importer admin page with create, keepalive, abort, decision resolution, and progress details.
* Local folder traversal, zip and nested zip traversal, GitHub zipball traversal, remote URL traversal, and WordPress REST traversal.
* Markdown, text, HTML, WXR, EPUB, PDF, media, REST relationship, and comment pipelines.
* Shared sanitized HTML block inference for local HTML, remote HTML, REST rendered content, WXR, and EPUB, including executable-attribute stripping, details/summary disclosures, pullquotes, timeline/step wrappers, Custom HTML form preservation, and classic fallback for opaque markup.
* First-party domain confirmation before URL rewriting.
* Optional host-scoped bearer-token or Basic authentication for remote URL and WordPress REST sources.
* Optional external PDF text extraction and scanned-PDF OCR through operator-configured local commands.

This plugin is under active development. Use a staging site and database backups before testing it on production content.

== Installation ==

1. Upload the packaged `universal-wordpress-importer` directory to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen.
3. If installing from a source checkout instead of a packaged release, run `composer install` in the plugin directory before activation.
4. Start an import from Tools > Universal Importer or with WP-CLI:

`wp universal-importer import ./content-export.zip`

== Frequently Asked Questions ==

= Does the importer resume after an interrupted request? =

Yes. Import state is stored in WordPress tables. Later WP-Cron, WP-CLI, or browser keepalive ticks continue from durable source items, cursors, media references, prepared documents, and idempotency records.

= Does it rewrite every absolute URL? =

No. The importer infers candidate first-party source domains and waits for operator confirmation. Confirmed exact hosts are rewritten to the local site URL; outside and lookalike domains are left unchanged.

= How do difficult PDFs work? =

Plain PDF text streams are extracted without external services. Directly embedded JPEG images are queued through the media pipeline and rewritten into the generated draft. PDFs that need a richer text pass can use `UNIVERSAL_IMPORTER_PDF_TEXT_COMMAND`, for example `pdftotext -layout {input} {output}`. Scanned PDFs require `UNIVERSAL_IMPORTER_PDF_OCR_COMMAND`, a local command template containing `{input}` and optionally `{output}` and `{scratch}` placeholders. Status output reports fidelity hints when first-pass processing detects unsupported embedded media, malformed or compressed PDF structures, or table/vector layout signals that were not preserved as structured layout.

= Can remote WordPress REST imports use credentials? =

Yes. Configure `UNIVERSAL_IMPORTER_REMOTE_AUTH_HOSTS` with exact allowed hosts, then set either `UNIVERSAL_IMPORTER_REMOTE_BEARER_TOKEN` or `UNIVERSAL_IMPORTER_REMOTE_BASIC_USER` and `UNIVERSAL_IMPORTER_REMOTE_BASIC_PASSWORD`. Credentials are attached only to matching hosts and are not stored in import state.

= Where can I see operational docs? =

See the Markdown docs in the plugin `docs/` directory.

== Changelog ==

= 0.1.0 =

* Initial development release with durable sessions, shared continuation runner, admin and WP-CLI controls, local/archive/GitHub/remote traversal, first-pass document processors, media import, relationship/comment staging, WXR attachment handling, EPUB metadata, PDF text extraction, and optional OCR diagnostics.
