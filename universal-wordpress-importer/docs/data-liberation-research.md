# Data Liberation Research And Upstream Assessment

Last reviewed: 2026-05-14.

This note records the repo-local evidence for the original research and
php-toolkit requirements in `.autonomous-loop/goal.md`. It is intentionally
short and auditable: each external claim links to a primary source, and each
implementation note points to current local artifacts.

## Current Data Liberation Direction

The current WordPress.org Data Liberation roadmap frames the project as five
phases. The phase most directly relevant to this importer is Phase 2,
"Importing and Exporting Structured Data", which explicitly includes updating
links, copying media, recovering from errors, supporting large sites, and
developer extension for any content format:

- https://wordpress.org/data-liberation/

That same roadmap now lists Phase 3 as a browser-extension flow for liberating
data from closed platforms and Phase 4 as future direct WordPress-to-WordPress
synchronization. Those phases influenced the importer toward browser folder
upload support, staged local-tree imports, remote WordPress REST traversal, and
durable source-item state instead of a one-shot upload-only design.

The November 2024 Playground post is the clearest technical source for the
streaming/resume requirements. It calls out careful URL parsing across XML,
HTML, JSON, and URL formats, identifies non-streaming parser availability as a
problem, lists prototype streaming libraries, and names pause/resume of data
streaming as an open technical challenge:

- https://make.wordpress.org/playground/2024/11/06/using-playground-for-data-liberation-site-synchronization-and-building-streaming-parsers/

The January 2024 WordPress News post explains the project intent: make movement
to and within WordPress much easier, improve canonical importers, and share
community-owned migration workflows and scripts:

- https://wordpress.org/news/2024/01/data-liberation-in-2024/

The WordPress Briefing episode from April 2024 emphasizes Playground as a safe
staging/preview environment for migrations before applying content to a real
site:

- https://wordpress.org/news/2024/04/episode-77-lets-talk-about-data-liberation/

State of the Word 2024 explicitly revisited Data Liberation progress, including
an EPUB import demonstration and the role of Playground plus a browser
extension as a migration staging area:

- https://wordpress.org/news/2024/12/state-of-the-word-2024-legacy-innovation-and-community/

The Data Liberation GitHub discussions provide roadmap detail for the browser
extension and future site synchronization tracks:

- https://github.com/WordPress/data-liberation/discussions/79
- https://github.com/WordPress/data-liberation/discussions/80

State of the Word 2025 shifted the broad WordPress roadmap heavily toward AI
capabilities, the Abilities API, MCP adapter, and WordPress 6.9, but it remains
relevant operational context: import/migration tooling should be exposed through
predictable WordPress surfaces such as WP-CLI, REST/admin workflows, and
structured capabilities when future integrations need them:

- https://wordpress.org/news/2025/12/sotw-2025/

## WordPress Importer Mechanics

The canonical WordPress Importer repository remains focused on WXR-style
content import: posts, pages, custom post types, comments, custom fields,
terms, and authors. This repo mirrors that baseline for WXR while extending the
input model to arbitrary source trees and additional formats:

- https://github.com/WordPress/wordpress-importer

Local evidence:

- `src/Import/SourceItemDocumentProcessor.php` stages WXR entities into
  prepared documents, attachment references, postmeta, comments, terms, authors,
  and navigation-menu related metadata.
- `tests/Unit/Import/ImportRunnerTest.php` covers WXR post/comment/postmeta/
  attachment/menu flows and retry-safe persistence.

## Reprint Mechanics

Reprint is a WordPress site migration project that clones a site over HTTP by
installing an exporter plugin on the source site and running a target-side
importer. Its current README describes a `pull` command that preflights the
remote site, downloads files, downloads a SQL dump, imports the database,
generates runtime configuration, and starts a local runtime:

- https://github.com/adamziel/reprint

The design details most relevant to this importer are:

- A stable state directory is part of the command contract. Reprint's
  `--state-dir` stores migration state while `--fs-root` stores downloaded
  files.
- Interrupted pulls are resumed by re-running the same command. Completed
  pulls become delta syncs that download only changed files.
- The exporter/importer are split into Composer packages:
  `wp-php-toolkit/reprint-exporter` for streaming export of SQL dumps, file
  trees, and cursor-based resumption, and `wp-php-toolkit/reprint-importer` for
  the streaming importer CLI/PHAR. Both depend on php-toolkit Data Liberation
  packages.
- Low-level hosting-platform commands expose progress/status surfaces, partial
  completion exit codes, cursor-backed file and database phases, and atomic
  status/state files.
- Database streaming documents explicit recovery from PHP fatal errors, OOM
  kills, `max_execution_time` expiry, and transport failure by persisting
  cursor/buffer state and returning a retryable partial-completion exit code.

Local design mapping:

- Reprint's filesystem state directory maps to this plugin's WordPress-backed
  state tables in `src/Import/WordPressImportSessionStore.php` and managed
  cache roots in `src/Import/ImportCacheDirectory.php`. This importer stores
  state in WordPress tables because it runs as a plugin and must expose
  progress through admin, WP-Cron, and WP-CLI surfaces.
- Reprint's cursor-backed file/database phases map to importer source-item
  cursors, prepared-document cursors, WXR entity cursors, EPUB spine cursors,
  zip entry cursors, local directory cursors, and idempotency records.
- Reprint's rerun-to-resume contract maps to `wp universal-importer tick`,
  admin keepalive ticks, and WP-Cron continuation. Hidden WP-CLI runner
  controls exercise crash, timeout, memory pressure, and idempotency gaps.
- Reprint's partial-completion/status model maps to importer session statuses,
  decision rows, progress events, and CLI/admin status summaries.

This repo does not vendor Reprint directly. Reprint is a full site clone/sync
tool that handles files, SQL, and runtime setup; this plugin imports content
into an existing WordPress site. The adopted part is the operational model:
small resumable phases, durable cursors, explicit partial completion, atomic or
idempotent state updates, and status files/tables that a UI can poll.

## php-toolkit Usage

The repo directly depends on `wp-php-toolkit/data-liberation`:

- `composer.json`
- `composer.lock`

The locked package set is `v0.1.5` for `wp-php-toolkit/data-liberation` and its
transitive `bytestream`, `encoding`, `filesystem`, `http-client`, and `xml`
components. The upstream php-toolkit repository has continued moving; the
repository page showed latest release `v0.7.5` on 2026-05-14:

- https://github.com/WordPress/php-toolkit

Local production code uses php-toolkit in
`src/Import/SourceItemDocumentProcessor.php`:

- `WordPress\ByteStream\ReadStream\FileReadStream`
- `WordPress\ByteStream\ReadStream\ByteReadStream`
- `WordPress\ByteStream\ByteStreamException`
- `WordPress\DataLiberation\EntityReader\WXREntityReader`
- `WordPress\DataLiberation\ImportEntity`

The WXR path uses `WXREntityReader::create( $stream, $cursor )` with stored
cursor metadata for resumable entity processing. Markdown, text, HTML, and PDF
paths also use byte streams for bounded reads instead of whole-file loading
where that matters.

Local verification evidence:

- `tests/Unit/Import/ImportRunnerTest.php` covers WXR cursor resume,
  WXR comments, postmeta, attachments, relationship mapping, menu persistence,
  and attachment metadata/parent repair paths.
- `tests/Unit/Import/ImportRunnerTest.php` also includes split executable HTML
  stripping coverage that exercises byte-stream chunk boundaries.
- `docs/architecture.md` records the WXR/php-toolkit streaming design.
- `CHANGELOG.md` records the byte-stream and WXR data-liberation integration.

## Upstream Patch Decision

No local blocker currently requires a php-toolkit patch PR stack.

Rationale:

- The current importer successfully uses php-toolkit byte streams and
  `WXREntityReader` for the areas where local code depends on the toolkit.
- Recent hardening work for huge local directories and zip archives was
  importer orchestration work, not an upstream php-toolkit defect: it stores
  cursors on importer source items and does not require modifying toolkit APIs.
- The existing external php-toolkit PR #127, merged on 2025-06-04, is useful
  context for WXR/Data Liberation importer direction in Blueprints, but it is
  upstream work that has already landed and is not a local patch stack:
  https://github.com/WordPress/php-toolkit/pull/127

If a future slice finds that php-toolkit cannot meet a concrete requirement,
the required artifact is:

- a branch or patch against `WordPress/php-toolkit`,
- a PR URL or local PR-stack note,
- a Composer override or patch configuration documenting exactly how this repo
  consumes the patched code, and
- focused tests proving the local importer behavior that required the patch.

Until then, the php-toolkit requirement is satisfied by direct package use plus
the documented assessment above; the conditional PR requirement is not
triggered by current evidence.
