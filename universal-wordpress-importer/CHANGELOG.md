# Changelog

## Unreleased

- Added php-toolkit Git plumbing for explicit GitHub branch/subtree imports,
  with GitHub tree/blob and zipball fallbacks, and added OPML feed-list imports.
- Added admin source shortcuts and a keyboard-navigable browser folder tree
  preview for selected files.
- Fixed GitHub subtree imports when an ambiguous slash-containing ref candidate
  throws a php-toolkit Git exception, and made admin AJAX failures report
  non-JSON server responses instead of leaking raw JSON parse errors.
- Fixed autonomous loop prompt rendering so literal backticked guidance remains
  in agent prompts instead of being interpreted by the shell, and expanded the
  runner smoke test to verify prompt integrity.
- Persisted CLI/admin dry-run choices on durable import sessions and through
  WordPress session storage, surfaced the flag in CLI/admin session status,
  and made dry-run runner ticks keep importer staging work active while
  skipping WordPress content mutation stages, then mark dry-run sessions done
  after importer-state work drains and pending decisions are resolved.
- Fixed WP-CLI status checkpoint rendering to use stored processed counts and
  covered completed dry-run local filesystem sessions through CLI `tick` and
  `status`.
- Covered completed dry-run local filesystem sessions through admin keepalive
  snapshots.
- Marked non-dry-run local Markdown file sessions done after traversal, media
  import, prepared document idempotency, and post idempotency complete, while
  keeping blocked media sessions running.
- Broadened non-dry-run local single-file completion to `.mdown`, `.txt`,
  `.text`, `.html`, and `.htm` document sources under the same decision, media,
  source-item, and idempotency gates.
- Broadened non-dry-run local PDF completion under the same gates, including
  native text PDFs and PDFs with extracted embedded JPEG media, while keeping
  failed textless or corrupt PDFs running for operator recovery.
- Covered successful external-text and OCR PDF helper paths through normal
  completion with first-party URL rewriting, post persistence, and idempotency
  assertions.
- Broadened non-dry-run local EPUB completion under the same gates, with
  additional blocking for deferred or unresolved EPUB internal links.
- Grouped consecutive paragraph, list, blockquote, preformatted, table,
  figure, direct image, direct picture, direct video, direct audio, and orphan
  list-item siblings, direct native anchor links, address, legacy center wrappers, code,
  nested Details disclosures, legacy menu/dir lists, legacy inline font
  wrappers, structured semantic sections, articles, asides, headers, and
  footers, structured main content and div wrappers, native Navigation
  wrappers, FAQ definition lists, visible `noscript`, `noframes`, and
  `noembed` fallbacks, obsolete `marquee` wrappers, non-form fieldsets, visible
  open dialogs, fallback-only object wrappers, obsolete `applet` fallbacks,
  obsolete preformatted tags, text-node bodies, Search forms, separators,
  guarded standalone inline phrasing wrappers, obsolete static inline wrappers,
  and known direct iframe embeds after orphan `summary` elements into one
  native Details block, while stopping before headings or opaque content.
- Unwrapped obsolete `marquee` content into native blocks when structurally
  inferable, while preserving empty or opaque marquee wrappers as Classic
  fallback.
- Unwrapped block-child `address` contact content into native Paragraph blocks
  when the wrapper contains only structured paragraph children, while
  preserving broader or opaque address wrappers as Classic fallback.
- Unwrapped visible `dialog open` content into native blocks when structurally
  inferable, while preserving closed dialogs, form/control dialogs, and
  ambiguous dialogs as Classic fallback.
- Unwrapped non-form `fieldset` content into native blocks when it has a
  visible legend and structurally inferable children, while preserving real
  form fieldsets, untitled fieldsets, and ambiguous fieldsets as Classic
  fallback.
- Unwrapped fallback-only `object` content into native blocks when the wrapper
  has no resource/configuration attributes and its children are structurally
  inferable, while preserving configured or ambiguous objects as Classic
  fallback.
- Unwrapped visible `noembed` fallback content into native blocks when its
  children are structurally inferable, while preserving empty or ambiguous
  `noembed` markup as Classic fallback.
- Unwrapped visible obsolete `applet` fallback content into native blocks when
  structurally inferable, skipping `param` metadata while preserving param-only
  or ambiguous applets as Classic fallback.
- Unwrapped visible `noframes` fallback content into native blocks when its
  children are structurally inferable, while preserving ambiguous `noframes`
  markup as Classic fallback.
- Unwrapped structured `hgroup` wrappers into native Heading/Paragraph blocks,
  while preserving opaque or custom `hgroup` contents as Classic fallback.
- Converted text-only obsolete preformatted tags (`listing`, `plaintext`, and
  `xmp`) into native Preformatted blocks, while preserving parsed child-element
  cases as Classic fallback.
- Folded orphan `figcaption` elements into immediately preceding valid gallery
  wrappers as native Gallery captions, while preserving late captions and
  one-image gallery-looking wrappers as separate fallback content.
- Folded orphan `figcaption` and `cite` citation elements into immediately
  preceding pullquote blockquotes as native Pullquote citations, while
  preserving late citations and pullquotes that already contain citations.
- Folded orphan `figcaption` and `cite` citation elements into immediately
  preceding blockquotes as native Quote citations, while preserving late
  citations and quotes that already contain citations.
- Folded orphan `figcaption` elements into immediately preceding media/embed
  nodes as native Video, Audio, or known-provider Embed captions, while
  preserving separated captions and unknown iframe captions as fallback.
- Folded orphan `figcaption` elements into immediately preceding image-like
  nodes as native Image captions, including plain images, linked images, and
  responsive pictures, while preserving separated or invalid picture captions
  as Classic fallback.
- Converted orphan `summary` elements plus one immediately following
  structured body node into native Details blocks, while preserving empty
  summaries and opaque body content as Classic fallback.
- Folded trailing orphan table `caption` elements into immediately preceding
  orphan row/cell/section Table blocks, while preserving later unrelated
  captions as Classic fallback.
- Converted leading orphan table metadata fragments such as `caption` and
  `colgroup` into the following native Table block when row content is present,
  while preserving metadata-only or malformed fragments as Classic fallback.
- Converted consecutive top-level orphan `thead`/`tbody`/`tfoot` table
  sections into native Table blocks when they contain only rows, while
  preserving malformed section fragments as Classic fallback.
- Converted consecutive top-level orphan table rows or cells into native Table
  blocks by rebuilding the missing table wrapper around coherent row/cell
  fragments.
- Converted consecutive top-level orphan FAQ-style `dt`/`dd` pairs into native
  Details blocks, while preserving ordinary non-FAQ definition fragments as
  Classic fallback.
- Converted consecutive top-level orphan `li` elements into native unordered
  List blocks, preserving list-item anchors and value overrides with nested
  List Item blocks.
- Converted standalone `canvas` elements into native Paragraph blocks when
  they contain visible paragraph-safe fallback content, while preserving empty
  or block-child canvas markup as Classic fallback.
- Converted standalone text-only `label` elements into native Paragraph blocks
  when they contain paragraph-safe children, while preserving labels that wrap
  form controls as Classic fallback.
- Converted standalone `output` elements into native Paragraph blocks when
  they contain visible fallback text and paragraph-safe children, while
  preserving block-child output markup as Classic fallback.
- Converted standalone `meter` and `progress` elements into native Paragraph
  blocks when they contain visible fallback text and paragraph-safe children,
  while preserving block-child metric markup as Classic fallback.
- Converted standalone ruby annotation markup into native Paragraph blocks
  when it contains only paragraph-safe `ruby`/`rp`/`rt` phrasing content, while
  preserving block-child ruby markup as Classic fallback.
- Converted standalone semantic inline phrasing elements such as `abbr`,
  `acronym`, `kbd`, and `mark` into native Paragraph blocks when they contain
  visible inline content, while preserving empty wrappers as Classic fallback.
- Converted inline `address` wrappers into valid native Paragraph blocks when
  they contain paragraph-safe inline content, while preserving block-child
  address markup as Classic fallback.
- Treated static obsolete inline tags (`big`, `strike`, and `tt`) as
  paragraph-safe inline content while keeping animated legacy tags such as
  `blink` in Classic fallback.
- Converted legacy inline `font` wrappers into native Paragraph blocks when
  they contain paragraph-safe inline content, dropping obsolete font styling
  attributes while preserving ambiguous font markup as Classic fallback.
- Converted legacy `menu` and `dir` list containers into native unordered List
  blocks with canonical saved `<ul>` markup.
- Converted legacy `center` wrappers into native blocks when their children are
  structurally inferable, promoting center alignment to direct paragraphs and
  headings while preserving ambiguous center markup as Classic fallback.
- Converted visible `noscript` fallback content into native blocks when its
  children are already structurally inferable, while preserving ambiguous
  `noscript` markup as Classic fallback.
- Ignored copied document metadata elements such as `base`, `title`, `meta`,
  safe `style`, and stylesheet/resource `link` tags during imported HTML block
  inference, including metadata-only fragments.
- Added native File block inference for theme/plugin stylesheet asset links,
  including `.css` hrefs and `text/css` metadata.
- Added native File block inference for ENV export links, including `.env`,
  `application/env`, `application/x-env`, `text/env`, and `text/x-env`
  metadata.
- Added native File block inference for Java properties export links, including
  `.properties`, `application/properties`, `application/x-java-properties`,
  `application/x-properties`, `text/properties`, `text/x-java-properties`, and
  `text/x-properties` metadata.
- Added native File block inference for patch and diff export links, including
  `.patch`, `.diff`, `application/patch`, `application/x-patch`,
  `application/diff`, `application/x-diff`, `text/patch`, `text/x-patch`,
  `text/diff`, and `text/x-diff` metadata.
- Added native File block inference for dependency lock export links, including
  `.lock`, `application/lock`, `application/x-lock`, `text/lock`, and
  `text/x-lock` metadata.
- Added native File block inference for calendar export links, including
  `.ics`, `application/ics`, and `text/calendar` metadata.
- Added native File block inference for source map export links, including
  `.map`, `application/source-map`, `application/x-source-map`, and
  `text/x-source-map` metadata.
- Added native File block inference for web app manifest export links,
  including `.webmanifest` and `application/manifest+json` metadata.
- Added native File block inference for gettext translation export links,
  including `.po`, `.pot`, `.mo`, `text/x-gettext-translation`,
  `text/x-gettext-translation-template`, and `application/x-gettext` metadata.
- Added native File block inference for INI export links, including `.ini`,
  `application/ini`, `application/x-ini`, `text/ini`, and `text/x-ini`
  metadata.
- Added native File block inference for TOML export links, including `.toml`,
  `application/toml`, `text/toml`, and `text/x-toml` metadata.
- Added native File block inference for YAML export links, including `.yaml`,
  `.yml`, `application/yaml`, `application/x-yaml`, `text/yaml`, and
  `text/x-yaml` metadata.
- Added native File block inference for line-delimited JSON export links,
  including `.jsonl`, `.ndjson`, `application/ndjson`,
  `application/x-ndjson`, and `text/x-ndjson` metadata.
- Broadened SQLite File block coverage for `.db` and `.sqlite` database export
  hrefs plus legacy `application/x-sqlite` MIME metadata.
- Added native File block inference for SQLite database export links, including
  `.db`, `.sqlite`, `.sqlite3`, `application/vnd.sqlite3`, and legacy
  SQLite MIME metadata.
- Broadened File block coverage for extensionless SQL database export routes
  that provide a safe `.sql` filename only through the `download` attribute,
  while dropping generic `application/octet-stream` metadata.
- Added native File block inference for legacy SQL database export MIME
  metadata, including `application/x-sql` and `text/x-sql`.
- Added native File block inference for SQL database export links, including
  `.sql` hrefs and `application/sql` MIME metadata.
- Added native File block inference for zstd archive export links, including
  `.zst` hrefs and `application/zstd`/`application/x-zstd` MIME metadata.
- Added native File block inference for xz archive export links, including
  `.xz` hrefs and `application/x-xz` MIME metadata.
- Added native File block inference for shorthand tar-bzip archive export links
  using `.tbz` and `.tbz2` hrefs.
- Added native File block inference for bzip2 archive export links, including
  `.bz2` hrefs and `application/x-bzip2` MIME metadata.
- Added native File block inference for `.tgz` archive export links.
- Added native File block inference for tar archive export links, including
  `.tar` hrefs and `application/x-tar` MIME metadata.
- Added native File block inference for legacy `application/x-gzip` archive
  MIME metadata.
- Added native File block inference for gzip archive export links, including
  `.gz` hrefs and `application/gzip` MIME metadata.
- Added native File block inference for JSON export links, including `.json`
  hrefs and `application/json` MIME metadata.
- Trimmed trailing dot/hyphen padding from normalized imported File block
  `download` filenames after decoding unsafe suffix bytes.
- Trimmed trailing dot/hyphen padding from normalized URL-derived File block
  filenames after decoding unsafe suffix bytes.
- Trimmed traversal-like leading dot/hyphen padding from normalized URL-derived
  File block filenames.
- Trimmed traversal-like leading dot/hyphen padding from normalized imported
  File block `download` filenames.
- Normalized percent-encoded unsafe characters in imported File block
  `download` filenames while still rejecting literal unsafe filename
  characters.
- Collapsed consecutive decoded unsafe characters in URL-derived File block
  filenames to a single hyphen in native File block metadata.
- Normalized decoded unsafe characters in URL-derived File block filenames so
  native File block metadata stays filename-safe.
- Normalized encoded forward-slash and backslash bytes in URL-derived File
  block filenames so they do not serialize as slash-like characters in native
  File block metadata.
- Trimmed accidental outer whitespace from URL-derived File block filenames
  before candidate detection and native File block metadata serialization.
- Trimmed accidental outer whitespace from imported File block `download`
  filenames while preserving internal filename spaces.
- Replaced unsupported URL extensions with supported MIME-derived File block
  filename extensions instead of appending duplicate extensions.
- Normalized supported File block MIME metadata with parameters, such as
  `application/pdf; charset=binary`, while continuing to drop unsupported
  MIME types from File link metadata.
- Avoided false-positive File block inference for `cid:` attachment URLs with
  file-like names and `download` metadata.
- Avoided false-positive File block inference for local `file:` URLs with
  file-like paths and `download` metadata.
- Avoided false-positive File block inference for `blob:` URLs with file-like
  MIME and `download` metadata.
- Avoided false-positive File block inference for `data:` URLs with file-like
  MIME and `download` metadata.
- Preserved supported URL-derived File block filenames when an imported
  `download` attribute suggests an unsupported filename.
- Preferred supported imported `download` filenames over unsupported URL
  basenames when generating native File block filename metadata.
- Ignored unsupported imported `download` filenames such as `.exe` when
  generating File block filename metadata and Download button attributes.
- Broadened File block scheme coverage to verify protocol-relative document
  URLs become native File blocks with filename and anchor metadata.
- Broadened File block scheme coverage to verify legacy `ftp:` export URLs
  remain native File block candidates with filename and anchor metadata.
- Avoided false-positive File block inference for non-file URL schemes such as
  `mailto:` and `tel:`, even when they include file-like extensions.
- Avoided false-positive File block inference for fragment-only links with a
  `download` attribute, preserving those as normal in-page links.
- Added a fallback File block filename for query-only bare `download` links so
  generated export routes do not serialize empty filename metadata.
- Treated extensionless links with a bare `download` attribute as native File
  blocks, preserving their source anchor and URL-derived filename metadata.
- Broadened File block coverage to verify direct `.md` links become native
  File blocks with filename and source anchor metadata preserved.
- Broadened File block coverage to verify direct `.rtf` links become native
  File blocks with filename and source anchor metadata preserved.
- Broadened File block coverage to verify direct `.key` links become native
  File blocks with filename and source anchor metadata preserved.
- Broadened File block coverage to verify direct `.odp` links become native
  File blocks with filename and source anchor metadata preserved.
- Broadened File block coverage to verify direct `.ods` links become native
  File blocks with filename and source anchor metadata preserved.
- Broadened File block coverage to verify direct `.odt` links become native
  File blocks with filename and source anchor metadata preserved.
- Broadened File block coverage to verify direct `.csv` links become native
  File blocks with filename and source anchor metadata preserved.
- Broadened File block coverage to verify direct `.zip` links become native
  File blocks with filename and source anchor metadata preserved.
- Broadened File block coverage to verify direct `.epub` links become native
  File blocks with filename and source anchor metadata preserved.
- Broadened File block coverage to verify direct `.ppt` links become native
  File blocks with filename and source anchor metadata preserved.
- Broadened File block coverage to verify direct `.xls` links become native
  File blocks with filename and source anchor metadata preserved.
- Broadened File block coverage to verify direct `.doc` links become native
  File blocks with filename and source anchor metadata preserved.
- Broadened File block coverage to verify direct `.docx` links become native
  File blocks with filename and source anchor metadata preserved.
- Broadened File block coverage to verify direct `.txt` links become native
  File blocks with filename and source anchor metadata preserved.
- Broadened File block coverage to verify extensionless PPTX routes infer
  native filenames from safe imported OpenXML presentation MIME metadata.
- Broadened File block coverage to verify extensionless XLSX routes infer
  native filenames from safe imported OpenXML spreadsheet MIME metadata.
- Broadened File block coverage to verify extensionless CSV routes infer
  native filenames from safe imported `text/csv` MIME metadata.
- Broadened File block coverage to verify extensionless ZIP routes infer
  native filenames from safe imported `application/zip` MIME metadata.
- Broadened File block coverage to verify extensionless plain-text routes
  infer native filenames from safe imported `text/plain` MIME metadata.
- Broadened File block coverage to verify extensionless EPUB routes infer
  native filenames from safe imported `application/epub+zip` MIME metadata.
- Broadened File block coverage to verify extensionless RTF routes infer
  native filenames from safe imported `application/rtf` MIME metadata.
- Broadened File block coverage to verify extensionless Word routes infer
  native filenames from safe imported `application/msword` MIME metadata.
- Broadened File block coverage to verify extensionless PowerPoint routes
  infer native filenames from safe imported `application/vnd.ms-powerpoint`
  MIME metadata.
- Broadened File block coverage to verify extensionless Excel routes infer
  native filenames from safe imported `application/vnd.ms-excel` MIME
  metadata.
- Broadened File block coverage to verify extensionless OpenDocument
  Presentation routes infer native filenames from safe imported
  `application/vnd.oasis.opendocument.presentation` MIME metadata.
- Broadened File block coverage to verify extensionless OpenDocument
  Spreadsheet routes infer native filenames from safe imported
  `application/vnd.oasis.opendocument.spreadsheet` MIME metadata.
- Broadened File block coverage to verify extensionless OpenDocument Text
  routes infer native filenames from safe imported
  `application/vnd.oasis.opendocument.text` MIME metadata.
- Inferred native File block filenames for extensionless XML export routes
  from safe imported `application/rdf+xml` MIME metadata.
- Inferred native File block filenames for extensionless XML export routes
  from safe imported `application/atom+xml` MIME metadata.
- Inferred native File block filenames for extensionless XML/WXR export routes
  from safe imported `application/rss+xml` MIME metadata.
- Inferred native File block filenames for extensionless EPUB routes from safe
  imported `application/x-epub+zip` MIME metadata.
- Preserved figure-wrapped linked image source anchors when the link carries
  the source `id`, moving that anchor to the native Image block wrapper and
  removing it from the inner link markup.
- Preserved wrapping link source anchors for linked responsive imported
  pictures when the picture itself has no `id`, moving the link `id` to the
  native Image block wrapper.
- Preserved wrapping link source anchors for standalone linked imported images
  when the image itself has no `id`, moving the link `id` to the native Image
  block wrapper instead of duplicating it inside saved markup.
- Preserved responsive picture source anchors for clear figure-wrapped
  imported images, including normalizing serialized `<source>` markup inside
  the native Image block output.
- Preserved inner image source anchors for clear figure-wrapped imported
  images when the figure itself has no `id`, moving the image `id` to the
  native Image block wrapper instead of duplicating it inside saved markup.
- Preserved safe wide/full layout alignment when imported pullquote
  blockquotes or figures are converted to native Pullquote blocks.
- Verified release zip package integrity at the start of release activation
  smoke runs, so supplied or freshly built zips fail before Playground/local
  runtime setup if the package is corrupt or malformed.
- Updated release packaging documentation to explain the automatic
  package-integrity check and the standalone `tools/verify-release-zip.php`
  command for already-built zips.
- Added PHPUnit coverage that runs `tools/build-release.php` with sandbox-safe
  dirty-tree release flags and asserts successful builds report package
  integrity verification.
- Updated `tools/build-release.php --help` to document that successful release
  builds verify package integrity before reporting success, with focused CLI
  help coverage.
- Wired `tools/build-release.php` to run the release zip integrity verifier
  after producing a package, so successful release builds now also confirm the
  generated zip's runtime files, root, and exclusions.
- Hardened the release zip verifier to reject Unix symlink entries using zip
  external attributes when the PHP zip extension exposes them.
- Hardened the release zip verifier to reject duplicate zip entry paths before
  package content checks, avoiding ambiguous extraction behavior.
- Hardened the release zip verifier to reject ambiguous `.` and duplicate
  slash path segments while still allowing normal trailing-slash directory
  entries.
- Hardened the release zip verifier to reject unsafe zip entry paths such as
  traversal segments, absolute paths, and backslash-delimited paths before
  package content checks.
- Broadened release zip verifier coverage for CLI usage behavior, including
  `--help` and missing required `--zip` arguments.
- Broadened release zip verifier coverage to reject corrupt files that cannot
  be opened as zip archives.
- Broadened release zip verifier coverage to reject excluded development tree
  paths such as nested `tests/` fixtures.
- Broadened release zip verifier coverage to reject packages missing required
  runtime files such as Composer's vendor autoload.
- Broadened release zip verifier coverage to reject malformed packages with
  files outside the plugin root or excluded release tooling inside the zip.
- Added PHPUnit coverage for `tools/verify-release-zip.php` so the release
  zip verifier is exercised against both builder-produced packages and missing
  zip paths.
- Added `tools/verify-release-zip.php` so package-integrity checks for built
  release zips are repeatable without WordPress activation or external smoke
  runtimes.
- Verified that applying the fallback patches in a writable temporary clone can
  produce a normal Git checkpoint commit, confirming the read-only `.git`
  mount is the remaining local commit blocker.
- Diagnosed the commit blocker as a mount-level read-only `.git` filesystem:
  ownership and mode bits are normal for `claude`, but `findmnt` reports
  `.git` mounted with `ro`.
- Marked the recovery patch verifier executable and refreshed its fallback
  patch so restored copies can be run directly.
- Verified that the fallback recovery path can bootstrap its own checker by
  applying the patches in a temporary clean clone and running the recovered
  verifier script there.
- Added a repo-local recovery patch verifier and captured it as an ignored
  fallback patch artifact so the read-only Git recovery path can be checked
  mechanically.
- Refreshed the broad local verification baseline for the current dirty tree:
  Composer metadata validation, full PHPUnit, PHPCS, and whitespace checks pass
  while the external Git and release-smoke blockers remain open.
- Verified the fallback recovery patches by applying them to a clean `HEAD`
  export and comparing the recovered file hashes against the current dirty
  worktree.
- Verified the dirty-built release zip's package integrity without activation:
  required runtime files and vendor autoload are present, the zip uses a single
  plugin root, and release exclusions omit loop, test, tool, dist, and dev
  artifacts.
- Added an ignored recovery manifest beside the fallback patches with exact
  restore commands and patch hashes for a future writable-Git session.
- Verified the ignored recovery patch artifacts by applying them forward to a
  clean temporary `HEAD` export, strengthening the fallback recoverability
  evidence while real Git commits remain blocked.
- Added ignored recovery patch artifacts for the current dirty tracked work and
  untracked `AGENTS.md` file so the accumulated changes remain recoverable
  while `.git` is read-only and real commits are blocked.
- Classified the remaining release-smoke runtime blockers in this sandbox:
  local smoke cannot create Unix or TCP sockets for its private runtime, and
  Playground smoke cannot fetch `@wp-playground/cli` from npm.
- Verified that the current dirty tree can still build a packaged release zip
  when explicitly allowed, while recording that the local release smoke cannot
  complete in this sandbox because private MariaDB cannot bind its Unix socket.
- Audited completion readiness against the top-level goal and reopened concrete
  follow-ups for release recoverability: full PHPUnit, PHPCS, Composer metadata,
  and whitespace checks pass, but the dirty read-only Git worktree prevents
  commits and causes the clean release build gate to fail.
- Closed the admin UX, WP-Cron continuation, and browser keepalive checklist
  after verifying admin keepalive/status/decision controls, CLI import/status/
  resume/decide/tick coverage, relationship warning and comment-count status
  output, EPUB TOC and PDF/OCR summaries, and runner tick-control coverage.
- Closed the media import, first-party domain confirmation, and URL rewriting
  checklist after verifying URL rewriting, local and remote media import,
  media idempotency recovery, WXR postmeta attachment URL/id remapping, and
  WordPress REST featured-media sideloading coverage.
- Closed the block conversion checklist after verifying the full HTML block
  converter suite plus runner integration coverage for local HTML, remote HTML,
  REST fallback rendered HTML, classic fallback, and split-chunk script
  stripping.
- Closed the format processor checklist after verifying mixed Markdown/text
  archive processing, WXR entities and attachments, EPUB spine/nav/media
  handling, PDF text/OCR/table handling, Markdown streaming, HTML
  script-stripping and fallback coverage, and Markdown media reference queuing.
- Closed the WXR attachment/PDF-OCR/richer-media processor checklist after
  verifying WXR attachment metadata, thumbnail remapping, attachment parent
  restoration, PDF embedded-media extraction/diagnostics, external text
  extraction, OCR fallback, and layout-aware table conversion coverage.
- Closed the source tree traversal checklist after verifying local files and
  folders, zips, nested archives, GitHub archives, remote URLs, WP REST sites,
  alternate REST roots, auth failures, pagination failures, rate limits,
  custom post types, comments, relationships, and featured media traversal.
- Closed the adversarial local import fixture checklist after covering broken,
  malicious, huge, duplicate, interrupted, and ambiguous local import cases.
- Broadened adversarial local import fixture coverage to verify malformed local
  HTML still imports with readable content.
- Broadened adversarial local import fixture coverage to verify ambiguous HTML
  figure markup keeps editorial children in Classic fallback output.
- Broadened adversarial local import fixture coverage to verify a large text
  document imports through the streamed local runner path.
- Broadened adversarial local import fixture coverage to verify scriptable
  HTML attributes and URLs are stripped during runner preparation.
- Inferred native File block filenames for extensionless XML routes from safe
  imported `text/xml` MIME metadata.
- Inferred native File block filenames for extensionless XML routes from safe
  imported `application/xml` MIME metadata.
- Treated `.xml` links as supported native File block candidates.
- Treated `.wxr` links as supported native File block candidates.
- Treated `.text` links as supported native File block candidates.
- Treated `.log` links as supported native File block candidates.
- Treated `.mdown` links as supported native File block candidates.
- Treated `.markdown` links as supported native File block candidates.
- Inferred native File block filenames for extensionless Markdown routes from
  safe imported `text/x-markdown` MIME metadata.
- Inferred native File block filenames for extensionless Markdown routes from
  safe imported `text/markdown` MIME metadata and treated `.md` links as
  supported File block candidates.
- Inferred native File block filenames for extensionless log routes from safe
  imported `text/x-log` MIME metadata.
- Inferred native File block filenames for extensionless Excel routes from safe
  imported `application/x-ms-excel` MIME metadata.
- Inferred native File block filenames for extensionless Excel routes from safe
  imported `application/msexcel` MIME metadata.
- Inferred native File block filenames for extensionless Excel routes from safe
  imported `application/x-msexcel` MIME metadata.
- Inferred native File block filenames for extensionless Excel routes from safe
  imported `application/x-excel` MIME metadata.
- Inferred native File block filenames for extensionless PDF routes from safe
  imported `application/acrobat` MIME metadata.
- Inferred native File block filenames for extensionless PDF routes from safe
  imported `application/x-pdf` MIME metadata.
- Inferred native File block filenames for extensionless RTF routes from safe
  imported `application/x-rtf` MIME metadata.
- Inferred native File block filenames for extensionless RTF routes from safe
  imported `text/richtext` MIME metadata.
- Inferred native File block filenames for extensionless Word routes from safe
  imported `application/word` MIME metadata.
- Inferred native File block filenames for extensionless PowerPoint routes from
  safe imported `application/mspowerpoint` MIME metadata.
- Inferred native File block filenames for extensionless PowerPoint routes from
  safe imported `application/x-mspowerpoint` MIME metadata.
- Inferred native File block filenames for extensionless PowerPoint routes from
  safe imported `application/powerpoint` MIME metadata.
- Inferred native File block filenames for extensionless RTF routes from safe
  imported `text/rtf` MIME metadata.
- Inferred native File block filenames for extensionless Word routes from safe
  imported `application/x-msword` MIME metadata.
- Inferred native File block filenames for extensionless Excel routes from
  safe imported `application/excel` MIME metadata.
- Inferred native File block filenames for extensionless CSV routes from safe
  imported `text/x-csv` MIME metadata.
- Inferred native File block filenames for extensionless ZIP routes from safe
  imported `application/x-zip` MIME metadata.
- Inferred native File block filenames for extensionless CSV routes from safe
  imported `application/csv` MIME metadata.
- Inferred native File block filenames for extensionless ZIP routes from safe
  imported `application/x-zip-compressed` MIME metadata.
- Inferred native File block filenames for extensionless Keynote routes from
  safe imported Keynote MIME metadata.
- Broadened File block coverage to verify extensionless DOCX routes infer
  native filenames from safe OpenXML MIME metadata.
- Broadened Gallery and linked Picture coverage to verify mixed-case reserved
  `target` values still preserve native Image link metadata.
- Broadened Navigation coverage to verify mixed-case reserved `target` values
  still preserve native new-tab metadata.
- Broadened Social Icons coverage to verify mixed-case reserved `target`
  values still preserve native new-tab metadata.
- Normalized imported Media & Text custom image-link reserved `target`
  metadata to canonical lowercase values in block attributes.
- Normalized imported linked Image reserved `target` metadata to canonical
  lowercase values in block attributes.
- Normalized imported File text-link reserved `target` metadata to canonical
  lowercase values in block attributes and saved link markup.
- Normalized imported Button link reserved `target` metadata to canonical
  lowercase values in block attributes and saved link markup.
- Normalized imported Media & Text custom image-link `rel` metadata and saved
  link markup to safe lowercase deduplicated tokens.
- Normalized imported Button link `rel` metadata and saved link markup to safe
  lowercase deduplicated tokens.
- Normalized imported Social Icons link `rel` metadata to safe lowercase
  deduplicated tokens.
- Normalized imported Navigation link and submenu `rel` metadata to safe
  lowercase deduplicated tokens.
- Normalized imported linked Image `rel` metadata and saved link markup to safe
  lowercase deduplicated tokens.
- Normalized imported File block text-link `rel` metadata to safe lowercase
  deduplicated tokens.
- Broadened Separator coverage to verify safe mixed-case alignment and core
  style class tokens preserve native metadata.
- Broadened Search block coverage to verify safe inline shorthand auto-margin
  styles preserve native center alignment metadata.
- Broadened native Audio coverage to verify safe inline shorthand auto-margin
  styles preserve native center alignment metadata.
- Broadened classic media shortcode coverage to verify safe inline shorthand
  auto-margin styles preserve native Video center alignment metadata.
- Broadened Embed block coverage to verify safe inline shorthand auto-margin
  styles preserve native center alignment metadata.
- Broadened File block coverage to verify safe inline auto-margin styles
  preserve native center alignment metadata.
- Preserved imported Details/FAQ/accordion open state from safe `is-open`-style
  disclosure class tokens.
- Preserved imported standalone Details open state from safe disclosure class
  tokens such as `open`.
- Normalized shared imported semantic class-token matching so mixed-case
  Button-style classes still drive native block inference.
- Normalized safe mixed-case imported text alignment class tokens for native
  Paragraph and other text-aligned block metadata.
- Preserved imported ordered-list numbering type metadata from safe inline
  list-style shorthand declarations.
- Preserved imported ordered-list numbering type metadata from safe inline
  list-style-type declarations.
- Normalized safe mixed-case imported Table fixed-layout and striped-style
  class tokens into native Table metadata.
- Normalized safe mixed-case imported Table alignment class tokens such as
  `AlignWide` into native Table alignment metadata.
- Preserved imported Bootstrap-style `btn-outline-*` Button links as native
  outline Button style metadata.
- Preserved imported Button link `aria-label` metadata in native Button saved
  link markup.
- Recognized imported Bootstrap-style `btn-*` links as native Buttons/Button
  blocks.
- Normalized safe mixed-case imported alignment class tokens such as
  `AlignRight` in shared Image/media alignment metadata.
- Normalized safe mixed-case imported Image size class prefixes such as
  `SIZE-Large` into native lowercase image size metadata.
- Preserved imported Image and media center alignment from safe shorthand
  `margin: 0 auto` inline styles.
- Broadened Audio/Video coverage to verify legacy `align` attributes and safe
  centered inline styles preserve native media alignment.
- Preserved imported Image alignment from legacy `align` attributes and safe
  centered inline margin styles.
- Recognized imported route-style document links with safe supported MIME
  `type` metadata as native File blocks.
- Decoded safe percent-encoded imported File `download` filenames before
  using them as native File metadata and button download names.
- Recognized imported links with safe `download` filenames as native File
  blocks even when the href route does not expose a file extension.
- Preserved imported Table alignment from safe inline styles such as floated
  tables and centered auto-margin tables.
- Normalized safe mixed-case imported Code language class tokens, such as
  `Language-Go`, into lowercase native Code `language-*` metadata.
- Broadened Code/Preformatted coverage to verify unsafe `data-language`
  metadata does not force native Code conversion or leak unsafe classes.
- Broadened Code block language metadata coverage to verify standalone
  `<code data-lang>` values become safe native `language-*` classes.
- Preserved safe imported Code language metadata from `data-language` and
  `data-lang` attributes as native Code `language-*` classes.
- Converted imported `<pre>` snippets with safe language classes into native
  Code blocks even when they do not contain an inner `<code>` element.
- Preserved safe imported Code block language classes such as `language-php`
  and `lang-bash` as native Code `className` metadata.
- Broadened figure-wrapped Quote coverage to verify figures with extra direct
  editorial children keep a Classic fallback.
- Kept ambiguous Pullquote figures with extra direct children in the Classic
  fallback so imported editorial content is not dropped.
- Broadened figure-wrapped Pullquote coverage to verify harmless direct `<br>`
  separators are omitted while figcaptions remain native citations.
- Preserved figure-wrapped Quote conversion when harmless direct `<br>`
  separators appear between the blockquote and caption.
- Ran a broad verification checkpoint after the accumulated imported HTML media
  fidelity slices; full Composer test, lint, validation, and diff checks pass.
- Broadened native media source-selection coverage to verify scriptable first
  source candidates are stripped while later safe sources still drive native
  Audio block metadata.
- Preserved native media conversion when earlier `<source>` candidates are
  incomplete by selecting the first usable media source URL.
- Preserved legacy imported video `webkit-playsinline` metadata as native
  Video `playsInline` state and standard saved `playsinline` markup.
- Normalized imported native audio/video `preload` metadata, preserving only
  valid WordPress media values in block attributes and saved markup.
- Preserved alignment metadata from standalone imported audio/video elements
  when converting them into native media blocks.
- Preserved imported legacy accordion `data-open`/`data-expanded` metadata as
  open native Details blocks.
- Preserved imported native Details disclosures marked open with
  `aria-expanded`, `data-open`, or `data-expanded` metadata.
- Broadened FAQ definition-list data open-state coverage to verify
  description-side `data-expanded` opens native Details blocks.
- Preserved imported FAQ definition-list `data-open`/`data-expanded` state as
  open native Details blocks.
- Broadened FAQ definition-list `aria-expanded` regression coverage to verify
  description-side expanded state opens native Details blocks.
- Preserved imported FAQ definition-list `aria-expanded="true"` state as open
  native Details blocks.
- Broadened Spacer regression coverage to verify `min-height` takes precedence
  over `padding-top` when both fallback declarations are present.
- Broadened Spacer fallback coverage to verify unbounded `padding-top` values
  remain Classic fallback instead of native Spacer blocks.
- Preserved imported empty Spacer wrappers that express spacing with
  `padding-top` when no height metadata is present.
- Broadened Spacer fallback coverage to verify unbounded `min-height` values
  remain Classic fallback instead of native Spacer blocks.
- Broadened Spacer regression coverage to verify explicit inline `height`
  takes precedence over `min-height`.
- Preserved imported empty Spacer wrappers that express spacing with
  `min-height` when no explicit `height` is present.
- Broadened Search title-label regression coverage to directly verify form
  `title` fallback labels.
- Preserved imported Search input/form `title` metadata as native Search labels
  when no associated label or ARIA label is available.
- Broadened Search hidden-label regression coverage to directly verify
  `hidden` and `visibility:hidden` labels preserve `showLabel:false`.
- Preserved hidden-label intent for imported Search labels hidden with
  `hidden`, `display:none`, or `visibility:hidden`.
- Preserved accessible labels from imported submit-input Search buttons that
  omit visible values when deriving native Search block button text.
- Preserved `aria-label` metadata from imported image-submit Search buttons
  when deriving native Search block button text.
- Preserved accessible labels from imported image-submit Search buttons when
  deriving native Search block button text.
- Preserved accessible labels from imported icon-only Search submit buttons
  when deriving native Search block button text.
- Added regression coverage confirming imported Search forms preserve visible
  wrapper labels and submit-input button text in native Search blocks.
- Added regression coverage confirming File block conversion recognizes
  uppercase downloadable extensions and decodes percent-encoded filenames.
- Added regression coverage confirming linked responsive Picture imports
  preserve custom-link target and rel metadata in native Image output.
- Added regression coverage confirming imported Button links preserve safe
  `title` metadata in native block attributes and saved link markup.
- Added regression coverage confirming multiple standalone safe shortcode
  tokens in one source wrapper serialize as one anchored native Shortcode block.
- Added regression coverage confirming legacy More custom text comments decode
  and strip markup-like text before native More block serialization.
- Verified the accumulated imported HTML fidelity coverage through full
  PHPUnit, full PHPCS lint, strict Composer validation, and diff whitespace
  checks after the latest Cover, Gallery, Social Icons, Separator, Quote, and
  Pullquote metadata slices.
- Added regression coverage confirming figure-wrapped Pullquote blocks can use
  nested blockquote metadata for native text alignment and source anchors.
- Added regression coverage confirming figure-wrapped Quote blocks can use
  nested blockquote metadata for native text alignment and source anchors.
- Added regression coverage confirming imported Separator blocks preserve the
  safe core `is-style-wide` style together with full-width alignment and source
  anchors.
- Added regression coverage confirming direct-child wrapped Social Icons links
  are promoted from imported social wrappers, including icon-only Bluesky label
  fallback.
- Added regression coverage confirming linked images nested inside imported
  Gallery blocks preserve custom-link target and rel metadata.
- Added regression coverage confirming Cover blocks can infer safe background
  images from imported hero `data-bg-image` metadata while preserving anchors
  and full-width alignment.
- Verified the accumulated imported HTML fidelity coverage through full
  PHPUnit, full PHPCS lint, strict Composer validation, and diff whitespace
  checks after the latest Code, List, Paragraph/Heading, Details, and Media &
  Text metadata slices.
- Added regression coverage confirming Media & Text image custom-link target
  and rel metadata are preserved in native block attributes.
- Added regression coverage confirming FAQ definition-list descriptions can
  supply native Details anchors, open state, and wide/full alignment metadata.
- Added regression coverage confirming legacy `alignleft`/`alignright` classes
  on imported paragraphs and headings become native text alignment metadata
  without leaking layout-only alignment classes.
- Added regression coverage confirming imported ordered-list `start` values
  and list-item `value` overrides are bounded before native List/List Item
  serialization.
- Added regression coverage confirming Code block anchors are preserved when
  imported IDs live on direct `<code>` elements or nested `<pre><code>`
  children.
- Verified the accumulated imported HTML fidelity coverage through full
  PHPUnit, full PHPCS lint, strict Composer validation, and diff whitespace
  checks after the latest Table, shortcode media, File, and direct iframe Embed
  metadata slices.
- Added regression coverage confirming direct known-provider iframes convert to
  native Embed blocks while preserving source anchors, alignment, and canonical
  provider URLs.
- Added regression coverage confirming downloadable File block links preserve
  query/fragment URLs while validating imported MIME, language, referrer, and
  download-name metadata.
- Added regression coverage confirming classic video shortcodes can use body
  text as the media source while preserving safe preload metadata and dropping
  scriptable poster values.
- Added regression coverage confirming classic audio shortcodes can use body
  text as the media source while preserving enabled `autoplay` and `muted`
  metadata in native Audio blocks.
- Added regression coverage confirming plain imported tables preserve alignment
  attributes, fixed-layout style hints, striped table classes, and source
  anchors when converted to native Table blocks.
- Verified the accumulated imported HTML fidelity coverage through full
  PHPUnit, full PHPCS lint, strict Composer validation, and diff whitespace
  checks after the latest Navigation, Social Icons, Search, Spacer, and Embed
  metadata slices.
- Added regression coverage confirming standalone YouTube no-cookie embed links
  normalize to canonical public YouTube Embed block URLs while preserving source
  anchors.
- Added regression coverage confirming standalone Vimeo player links with
  normalized public labels convert to native Embed blocks with canonical URLs,
  anchors, and alignment metadata.
- Added regression coverage confirming imported Spacer wrappers prefer
  `data-spacer-height` metadata over inline style heights and preserve bounded
  viewport-unit spacer heights.
- Added regression coverage confirming imported search forms with text query
  inputs and image submit controls convert to native Search blocks with safe
  labels, placeholders, icon intent, anchors, and alignment metadata.
- Added regression coverage confirming direct-anchor imported social/profile
  wrappers convert to native Social Icons blocks while preserving safe link
  metadata and wrapper intent.
- Added regression coverage confirming direct-anchor imported navigation
  wrappers convert to native Navigation blocks while preserving safe link
  metadata and ignoring harmless separators.
- Added regression coverage confirming imported Navigation Submenu parent links
  preserve safe `title`, `rel`, and new-tab metadata.
- Verified the accumulated PDF stream parser hardening through full PHPUnit,
  full PHPCS lint, strict Composer validation, and diff whitespace checks after
  the latest length-aware stream boundary and diagnostic slices.
- Added embedded PDF JPEG coverage for lone carriage-return stream delimiters,
  verifying CR-delimited image streams stay length-aware and preserve payload
  bytes containing literal `endstream` text.
- Honored direct PDF stream `/Length` values after lone carriage-return stream
  delimiters, keeping CR-delimited streams on the length-aware extraction path.
- Preserved direct-length PDF text/content streams that contain literal object
  header-like bytes, limiting malformed-stream recovery to fallback-scanned
  streams.
- Continued PDF text/content stream extraction after malformed stream objects
  when a later object boundary is found before the eventual terminator, so one
  broken stream no longer hides later valid streams.
- Counted malformed PDF stream markers from the length-aware stream scanner,
  avoiding false structure warnings when direct-length payload text contains
  literal `stream` markers.
- Counted PDF streams in structure diagnostics with the same length-aware
  stream parser used for extraction, avoiding false stream counts when payload
  text contains fake stream markers.
- Honored direct PDF text/content stream `/Length` values before scanning for
  `endstream`, so literal sentinel text inside stream bytes does not truncate
  first-pass stream extraction.
- Honored direct PDF image stream `/Length` values before scanning for
  `endstream`, so embedded JPEG bytes that contain sentinel text are cached and
  queued without truncation.
- Added regression coverage confirming safe metadata attributes on unknown
  iframe embeds, such as `loading` and `referrerpolicy`, survive sanitized
  Custom HTML conversion.
- Verified the accumulated File block metadata hardening through full PHPUnit,
  full PHPCS lint, strict Composer validation, and diff whitespace checks after
  the latest text-link metadata slices.
- Preserved safe imported `referrerpolicy` attributes on native File block text
  links, allowing only standard policy tokens.
- Preserved safe imported `hreflang` attributes on native File block text links,
  normalizing valid language tags before serialization.
- Preserved safe imported MIME `type` attributes on native File block text
  links, normalizing valid MIME values before serialization.
- Preserved imported `aria-label` attributes on native File block text links so
  accessible labels survive File block conversion.
- Preserved imported `title` attributes on native File block text links while
  keeping File block comment metadata unchanged.
- Verified the accumulated imported HTML metadata hardening through full
  PHPUnit, full PHPCS lint, strict Composer validation, and diff whitespace
  checks after the latest Button and File block metadata slices.
- Preserved safe imported `download` filenames on native File block download
  buttons, reducing path-like values to a safe basename while preserving the
  previous generic download button behavior when no filename is supplied.
- Preserved safe core Button style classes (`is-style-outline` and
  `is-style-fill`) from imported button-styled links or their source wrappers
  when converting them into native Buttons/Button blocks, while continuing to
  drop arbitrary source style classes.
- Added focused regression coverage confirming imported Custom HTML sanitization
  strips `iframe srcdoc` payloads in both the DOM sanitizer and no-DOM regex
  fallback paths while preserving safe iframe attributes.
- Verified the accumulated HTML figure fallback hardening through full PHPUnit,
  full PHPCS lint, strict Composer validation, and diff whitespace checks after
  the latest captioned-image, image, media/embed, and table figure fallback
  slices.
- Kept ambiguous rendered classic captioned-image wrappers in the Classic
  fallback when they contain direct custom/editorial children that native Image
  conversion would otherwise drop. Harmless `<br>` separators between a classic
  image and caption remain ignored so ordinary caption markup still converts.
- Kept image figures in the Classic fallback when they contain direct
  custom/editorial children that do not belong in native Image block markup.
  Plain figures with direct images, links or pictures, and captions still
  convert normally.
- Kept media and known-provider embed figures in the Classic fallback when they
  contain direct custom/editorial children that native Video/Audio/Embed
  conversion would otherwise drop. Harmless `<br>` separators between media and
  captions still convert normally.
- Kept table figures in the Classic fallback when they contain direct
  custom/editorial children that native Table conversion would otherwise drop.
  Harmless `<br>` separators between tables and captions still convert
  normally.
- Kept responsive `<picture>` imports in the Classic fallback when they contain
  extra custom/editorial descendants that native Image conversion would
  preserve invalidly. Plain picture/source/img structures, including harmless
  `<br>` separators, still convert normally.
- Verified the accumulated PDF embedded-media diagnostics through full PHPUnit,
  full PHPCS lint, strict Composer validation, and diff whitespace checks after
  the latest incomplete-stream and filter-normalization slices.
- Diagnosed embedded PDF JPEG image streams with missing or indirect dimensions
  as unsupported media instead of queuing attachments with zero-sized image
  metadata. The unsupported reason is now persisted as `missing_dimensions` and
  the operator hint explains that the bounded parser could not resolve the
  dimensions.
- Diagnosed empty embedded PDF JPEG image streams as unsupported media instead
  of silently dropping them from extraction metadata. The unsupported reason is
  persisted as `empty_stream` with operator-facing hint text.
- Diagnosed malformed embedded PDF JPEG image streams that are missing an
  `endstream` terminator as unsupported media instead of losing them from
  extraction metadata. The unsupported reason is persisted as
  `malformed_stream` with operator-facing hint text. When a malformed image
  stream runs into a later PDF object marker, the extractor now resumes scanning
  from the later object so subsequent valid embedded images can still be queued.
- Diagnosed DCTDecode PDF image streams whose payload does not begin with a
  JPEG signature as unsupported media instead of queueing invalid `.jpg`
  attachments. Oversized streams still report the size-limit reason before
  payload validation.
- Normalized common abbreviated PDF image filter names before media extraction,
  so `/Filter /DCT` is treated as `DCTDecode` and valid embedded JPEG streams
  are queued instead of being reported as unsupported. Standard abbreviated
  filter-chain names such as `/A85` are also expanded in unsupported media
  diagnostics.
- Diagnosed chained embedded PDF image filters such as
  `ASCII85Decode+DCTDecode` as unsupported media streams instead of treating
  them as directly extractable JPEGs. The diagnostic now preserves the full
  filter chain in source-item and prepared-document metadata.
- Verified the accumulated imported HTML fidelity hardening through full
  PHPUnit, full PHPCS lint, strict Composer validation, and diff whitespace
  checks after the latest separator-preservation slices.
- Preserved native Group conversion for clear imported timeline and step-list
  wrappers that include direct `<br>` separators between items, while still
  rejecting real unexpected timeline children.
- Preserved native Details conversion for clear legacy accordion wrappers that
  include direct `<br>` separators between panels, while still rejecting real
  unexpected accordion wrapper children.
- Preserved native Details conversion for obvious FAQ/Q&A definition lists
  that include direct `<br>` separators between `dt`/`dd` entries, while still
  rejecting real malformed definition-list children.
- Preserved native Navigation conversion for clear imported menu wrappers,
  top-level lists, and submenu items that include direct `<br>` separators,
  while still rejecting real unexpected menu children.
- Preserved native Media & Text conversion for clear imported image-plus-copy
  split layouts that include direct `<br>` separators between the media and
  content sides, without changing other wrapper child handling.
- Preserved native Social Icons conversion for explicit imported social/profile
  link lists that include direct `<br>` separators between links, while still
  rejecting real mixed direct children.
- Preserved native Columns conversion for clear legacy row/column wrappers
  that include direct `<br>` separators between column children, while still
  rejecting real non-column children to avoid dropping imported content.
- Kept ambiguous imported gallery wrappers in the Classic fallback when they
  contain direct custom/non-gallery children that would otherwise be dropped
  during native Gallery conversion. Legacy `<br>` separators inside otherwise
  clear galleries remain ignored so ordinary gallery markup still converts.
- Hardened imported Custom HTML sanitization for SVG data URLs in inline CSS.
  The HTML fallback now strips `style` attributes containing
  `url(data:image/svg+xml,...)` in addition to existing scriptable URL
  patterns, and removes scriptable exact `data` URL attributes such as
  `<object data="data:image/svg+xml,...">`. Scriptable candidates inside
  `srcset` now drop the whole imported `srcset` attribute while preserving
  safe fallback `src` markup, and scriptable video `poster` URLs are removed
  before native Video block metadata is inferred. Legacy scriptable
  `background` URL attributes are also stripped, with unit coverage and clean
  local release smoke fixture coverage for packaged imports.
- Made the local release smoke harness tolerate successful MariaDB data
  directory initialization that writes environment warnings to stderr, while
  still failing on nonzero initialization exits.
- Added direct regression coverage for the rare no-DOM regex sanitizer
  fallback so scriptable URL-bearing attributes stay stripped even when
  `DOMDocument` is unavailable.
- Stripped scriptable meta refresh `content` targets during imported Custom
  HTML sanitization, including the no-DOM regex fallback.
- Stripped scriptable legacy image `dynsrc` and `lowsrc` URL attributes while
  preserving safe fallback `src` markup.
- Removed executable imported `<style>` blocks from DOM and no-DOM sanitizer
  paths while preserving the existing inline style attribute protections.
- Detected scriptable CSS `@import` targets in imported style attributes and
  style blocks as executable CSS.
- Stripped scriptable legacy object/applet `codebase`, `classid`, and
  `archive` URL attributes.
- Stripped scriptable `longdesc` and `cite` reference URL attributes while
  preserving the surrounding imported markup.
- Stripped `ping` attributes when any whitespace-separated endpoint is
  scriptable while preserving the safe link target.
- Normalized whitespace/control characters inside imported CSS checks so split
  scriptable protocols in `url(...)` and `@import` payloads are detected.
- Decoded simple CSS escape sequences before protocol detection so escaped
  scriptable CSS URLs are also removed.
- Added positive sanitizer coverage proving safe CSS escapes and safe relative
  CSS URLs plus safe `ping` URL lists are preserved.
- Preserved source anchors and safe alignment/size metadata for responsive
  HTML `<picture>` image imports. Standalone and linked picture elements now
  carry clear WordPress alignment classes, image size classes, custom link
  metadata, and wrapper anchors into native Image block attributes while
  avoiding duplicate source IDs. Unit coverage and the clean local release
  smoke fixture now verify the packaged behavior.
- Preserved safe wide/full layout alignment for imported Group block
  conversions. Callout/card wrappers, timeline and step-list wrappers, and
  card/pricing children now carry `alignwide`/`alignfull` source classes into
  native Group block `align` metadata and saved wrapper classes. Unit coverage
  and the clean local release smoke fixture now verify the packaged behavior.
- Preserved safe wide/full layout alignment for imported FAQ definition-list
  Details blocks and legacy accordion Details blocks. FAQ lists now pass
  `alignwide`/`alignfull` metadata from the specific term/answer or list
  wrapper into native Details output, and legacy accordion panels do the same
  from the panel/body or accordion wrapper. Unit coverage and the clean local
  release smoke fixture now verify the packaged behavior.
- Preserved safe wide/full layout alignment for imported Media & Text and
  Cover HTML conversions. Obvious split media layouts and hero/banner wrappers
  now carry WordPress `alignwide`/`alignfull` source classes into native block
  `align` metadata and saved wrapper classes, while unsupported left/center/right
  classes remain ignored for these layout-width-only blocks. Unit coverage and
  the clean local release smoke fixture now verify the packaged behavior.
- Preserved source anchors and safe alignment metadata for native Gallery
  block conversion during HTML import. Explicit legacy/native gallery
  wrappers now carry safe wrapper IDs into Gallery block anchors and saved
  figure IDs, and clear WordPress alignment classes into Gallery block
  `align` metadata plus wrapper classes. Unit coverage and the clean local
  release smoke fixture now verify the packaged behavior.
- Preserved source anchors and wide/full alignment for native Columns block
  conversion during HTML import. Obvious legacy grid wrappers now carry safe
  wrapper IDs into the Columns block anchor and saved wrapper ID, and direct
  column IDs into Column block anchors unless the child is promoted to a
  nested Group block that already owns the source anchor. Unit coverage and
  the clean local release smoke fixture now verify the packaged behavior.
- Preserved source anchors for native Code and Preformatted block conversion
  during HTML import. Imported `<pre><code>` snippets and plain `<pre>` blocks
  now carry safe source `id` values into native block anchors and saved
  wrapper IDs, keeping fragment links usable without falling back to Classic
  blocks. Unit coverage and the clean local release smoke fixture now verify
  the packaged behavior.
- Preserved source anchors and safe metadata for native Separator block
  conversion during HTML import. Imported horizontal rules now keep safe
  fragment IDs plus wide/full alignment and `is-style-wide` or `is-style-dots`
  metadata while dropping unsupported separator classes and executable
  attributes. Unit coverage and the clean local release smoke fixture now
  verify the packaged behavior.
- Preserved source anchors and obvious layout metadata for native File,
  Button, and Embed block conversion during HTML import. Download links,
  button-style links, iframe embeds, and bare provider URLs now carry safe
  source `id` values into native block anchors and saved wrapper IDs; File and
  Embed blocks preserve safe alignment classes, while Buttons preserves
  wide/full group alignment. Unit coverage and the clean local release smoke
  fixture now verify the packaged behavior.
- Preserved source anchors for native Audio and Video block conversion during
  HTML import. Figure-wrapped media, direct media elements, and rendered
  Classic Editor `wp-video`/`wp-audio` wrappers now carry safe source `id`
  values into native block `anchor` attributes and saved wrapper IDs. IDs
  moved from direct media elements are removed from the inner `<audio>` or
  `<video>` tag to avoid duplicate fragment targets. Unit coverage and the
  clean local release smoke fixture now verify the packaged behavior.
- Preserved source anchors for native Image and Table block conversion during
  HTML import. Standalone images, figure-wrapped images, figure-wrapped
  tables, and plain source tables now carry safe source `id` values into
  native block `anchor` attributes and saved wrapper IDs. Image IDs moved to
  the block wrapper are removed from the inner image markup to avoid duplicate
  IDs. Unit coverage and the clean local release smoke fixture now verify the
  packaged behavior.
- Preserved source list item anchors and safe ordered-list item value overrides
  during HTML import. Lists whose direct `<li>` children carry fragment IDs or
  bounded numeric `value` attributes now serialize nested native List Item
  blocks with matching anchors and saved `<li>` metadata, while invalid values
  are ignored and ordinary lists keep the existing compact output. Unit
  coverage and the clean local release smoke fixture now verify the packaged
  behavior.
- Preserved obvious Quote and Pullquote block text alignment and anchors during
  HTML import. Imported `<blockquote>` and figure-wrapped quote/pullquote
  markup with safe left/center/right alignment signals now serializes native
  `textAlign` attributes, canonical `has-text-align-*` classes, and source
  anchors while keeping existing citation preservation. Unit coverage and the
  clean local release smoke fixture now verify the packaged behavior.
- Preserved obvious Heading block text alignment during HTML import. Imported
  `<h1>` through `<h6>` elements with safe left/center/right alignment signals
  now serialize as native Heading blocks with matching `textAlign` attributes
  and canonical `has-text-align-*` classes while keeping existing anchor
  preservation. Unsupported alignment values are ignored conservatively. Unit
  coverage and the clean local release smoke fixture now verify the packaged
  behavior.
- Preserved obvious Paragraph block alignment and anchors during HTML import.
  Imported `<p>` elements with safe left/center/right alignment signals now
  serialize as native Paragraph blocks with matching `align` attributes and
  canonical `has-text-align-*` classes, while unsupported alignment values are
  ignored conservatively. Unit coverage and the clean local release smoke
  fixture now verify the packaged behavior.
- Preserved ordered-list numbering semantics during HTML import. Imported
  `<ol>` elements now carry safe `start`, `reversed`, `type`, and anchor
  metadata into native List block attributes and saved markup, while invalid
  list metadata is ignored conservatively. Unit coverage and the clean local
  release smoke fixture now verify the packaged behavior.
- Broadened the clean-release HTML smoke fixture to cover preserved legacy
  WordPress widget wrappers. The packaged smoke now imports explicit image and
  search widget containers and asserts the runtime output keeps their Classic
  fallback wrapper chrome, titles, IDs, forms, captions, and widget classes
  instead of flattening them into unrelated native blocks.
- Preserved explicit legacy WordPress widget wrappers on the conservative
  fallback path. Imported `widget_*`, `widget`, and `wp-block-legacy-widget`
  containers such as image and search widgets now keep their outer titles,
  IDs, classes, forms, captions, and wrapper semantics instead of being
  flattened into unrelated Image, Search, Heading, or List blocks.
- Preserved complex Classic Editor media widget wrappers on the conservative
  fallback path. Outer WordPress widget/textwidget containers that wrap legacy
  `wp-video`/`wp-audio` chrome now remain intact instead of being flattened
  into separate Heading and Audio/Video blocks, while direct obvious media
  wrappers still convert to native blocks.
- Converted rendered Classic Editor media wrappers into native media blocks.
  Obvious WordPress `wp-video`/`wp-audio` wrappers that contain a single
  audio or video element now serialize as editable Audio/Video blocks with
  safe alignment, source, preload, controls, and caption metadata preserved.
  Wrappers with extra editorial structure or multiple media elements remain on
  the conservative fallback path, and the clean-release HTML smoke fixture now
  verifies packaged classic media wrapper output.
- Preserved obvious Image block layout metadata. Imported classic
  `[caption]` shortcodes, rendered `wp-caption` wrappers, standalone images,
  and already-native image figures now carry clear WordPress alignment classes
  and common image size classes into native Image block attributes plus saved
  figure classes. Ambiguous source CSS remains ignored, and the clean-release
  HTML smoke fixture now verifies packaged aligned large captioned image
  output.
- Preserved obvious legacy/native HTML table layout metadata. Imported
  `wp-block-table`/legacy table wrappers now carry clear alignment classes,
  striped-table styling, and fixed-width-cell hints into native Table block
  attributes and saved markup. Ambiguous tables keep the existing conservative
  plain Table output, and the clean-release HTML smoke fixture now verifies
  packaged wide striped fixed-layout table output.
- Preserved obvious legacy HTML gallery layout metadata. Imported gallery
  wrappers with `gallery-columns-*` now serialize native Gallery blocks with
  the matching column count, and `gallery-size-*` now controls nested Image
  block `sizeSlug`/figure classes for common WordPress sizes. Ambiguous
  galleries keep the existing conservative behavior, and the clean-release
  HTML smoke fixture now verifies packaged gallery column and thumbnail output.
- Preserved custom link metadata for imported gallery images. Gallery children
  that contain linked images now serialize their nested Image blocks with
  `href` and `linkDestination:"custom"` instead of saying the image has no link
  while retaining linked markup. Unlinked gallery images still use
  `linkDestination:"none"`, and the clean-release HTML smoke fixture now
  verifies packaged linked-gallery output.
- Converted obvious classic WordPress media shortcodes into native blocks.
  Standalone `[embed]...[/embed]` shortcodes for known providers now become
  native Embed blocks, and straightforward `[audio]`/`[video]` shortcodes with
  `src` or format-specific source attributes now become editable Audio/Video
  blocks with safe player metadata preserved. Ambiguous or local unrecognized
  embed shortcodes continue to use the Shortcode block, and the clean-release
  HTML smoke fixture now verifies packaged media-shortcode output while
  allowing the existing media pipeline to rewrite imported poster images.
- Converted classic captioned HTML images into native Image blocks. Imported
  WordPress `[caption]...[/caption]` shortcodes and rendered `wp-caption`
  wrappers now preserve their image, link metadata, source anchor, and caption
  as editable `core/image` markup instead of splitting the caption away from
  the media. Standalone and linked responsive `<picture>` elements are also
  preserved inside Image blocks, and the clean-release HTML smoke fixture now
  verifies packaged classic caption output.
- Preserved legacy WordPress pagination markers as native blocks during HTML
  import. `<!--more-->` comments now serialize as `core/more`, including
  custom read-more text and adjacent `<!--noteaser-->` markers, while
  `<!--nextpage-->` comments serialize as `core/nextpage`. Unknown imported
  comments are still discarded instead of becoming opaque fallback markup, and
  the clean-release HTML smoke fixture now verifies packaged More and Page
  Break block output.
- Converted standalone imported shortcodes into native Shortcode blocks.
  Shortcode-only HTML paragraphs and text nodes now serialize as
  `core/shortcode`, including attribute-bearing and enclosing shortcodes,
  while mixed prose, bracketed editorial notes, and dangerous script/style
  shortcode tags remain normal paragraph content. The clean-release HTML smoke
  fixture now verifies packaged Shortcode block output.
- Converted figure-wrapped imported quotes into native Quote blocks. Plain
  `<figure><blockquote>...</blockquote><figcaption>...</figcaption></figure>`
  markup now serializes as `core/quote`, preserving the quote body and mapping
  figure captions to citations when the blockquote does not already include a
  citation. Pullquote-marked figures still use the Pullquote path, and the
  clean-release HTML smoke fixture now verifies packaged Quote block output
  from a legacy figure quote.
- Converted bare known-provider imported HTML links into native Embed blocks.
  Standalone provider URLs and links whose visible text is the same URL now
  serialize through the existing `core/embed` path for providers such as Vimeo,
  SoundCloud, YouTube, and Spotify, while labeled editorial links remain normal
  paragraph links. The clean-release HTML smoke fixture now verifies packaged
  Embed block output from a standalone provider link.
- Converted empty explicit imported spacer wrappers into native Spacer blocks.
  Legacy `spacer`/`gap`/`wp-block-spacer` wrappers with bounded CSS heights now
  serialize to `core/spacer`, preserving source anchors and rejecting
  contentful or unbounded spacer-like markup. The clean-release HTML smoke
  fixture now verifies packaged Spacer block output.
- Converted clear imported search forms into native Search blocks. GET forms
  with explicit search semantics and no extra filters now serialize to
  `core/search`, preserving labels, hidden-label intent, placeholders, icon
  button intent, and source anchors. Forms with unsupported controls continue
  through the sanitized Custom HTML path, and the clean-release HTML smoke
  fixture now verifies packaged Search block output.
- Converted explicit imported navigation wrappers into native Navigation blocks.
  Clear `<nav>`, role-navigation, and menu/navigation class wrappers now
  serialize to `core/navigation` with custom Navigation Link and simple
  Navigation Submenu inner blocks while preserving wrapper anchors,
  ARIA labels, link titles, rel metadata, and new-tab intent. Ambiguous
  menus fall back conservatively, and the clean-release HTML smoke fixture now
  verifies packaged Navigation output.
- Converted explicit imported social/profile link lists into native Social
  Icons blocks. Social wrappers with clear follow/social signals and at least
  two recognized services, including social nav wrappers around a nested list,
  now preserve wrapper anchors, visible-label intent, all-links-new-tab intent,
  service slugs, labels, and rel metadata while ordinary navigation lists keep
  the existing List path. The clean-release HTML smoke fixture now verifies
  packaged Social Icons output.
- Converted obvious imported image-plus-copy split layouts into native Media &
  Text blocks. Explicit `media-text`, `image-text`, `text-image`, and similar
  two-child wrappers now preserve source anchors, image alt text, linked image
  metadata, media position, and editable text-side inner blocks while
  ambiguous layouts keep the existing fallback path. The clean-release HTML
  smoke fixture now verifies packaged Media & Text output.
- Converted obvious imported FAQ/Q&A definition lists into native Details
  blocks. Well-formed `<dl>` structures with FAQ/question signals now preserve
  source anchors, inline answers, block answers, and open-state hints as
  editable `wp:details` blocks, while ordinary glossary-style definition lists
  continue to use the conservative fallback. The clean-release HTML smoke
  fixture now verifies packaged definition-list FAQ output.
- Added conservative WXR navigation menu theme-location assignment. Imported
  WXR menus now assign themselves to clearly matching registered theme
  locations when that location is empty or already points at the same menu,
  using WordPress' current `nav_menu_locations` theme-mod pattern and core
  common-location matching groups. Occupied or ambiguous locations are left
  unchanged with observable warning events, and assignment retries after a
  later tick even when menu items are already idempotent.
- Added first-pass WXR navigation menu import. `nav_menu_item` posts are now
  staged from WXR exports, menu custom URLs join first-party domain
  confirmation, confirmed source-site menu URLs are rewritten to the local
  site, post-type menu targets are remapped to imported draft permalinks, WXR
  taxonomy menu targets resolve to local taxonomy menu items when source term
  metadata can be matched, and parent/target gaps defer with explicit progress
  events instead of being guessed.
- Added first-party URL confirmation and rewriting for WXR postmeta values.
  Absolute URL domains discovered only inside WXR metadata now join the
  existing confirmation decision, and confirmed source-site URLs are rewritten
  before metadata is persisted to imported drafts, including inside serialized
  builder metadata while outside domains remain unchanged.
- Converted obvious imported poem, lyrics, stanza, and existing
  `wp-block-verse` `<pre>` elements into native Verse blocks. Source anchors
  and whitespace-sensitive line breaks are preserved, while ordinary
  preformatted text and code blocks keep their existing paths. The
  clean-release HTML smoke fixture now verifies packaged Verse output.
- Converted obvious imported HTML hero, banner, jumbotron, page-hero, and
  already-native cover wrappers into native Cover blocks when a background
  image or leading image is present and the overlay content can be safely
  inferred as blocks. Source anchors, image alt text, headings, paragraphs, and
  CTA buttons are preserved; opaque hero content still falls back to Classic.
  The clean-release HTML smoke fixture now verifies packaged Cover output.
- Preserved obvious imported HTML pricing, plan, comparison, and card-grid
  wrappers as native Columns blocks with nested editable Group cards. Card-like
  plan/tier children keep source anchors and structured inner blocks, while
  mixed or opaque grids still fall back to Classic. The clean-release HTML
  smoke fixture now verifies packaged pricing-grid output.
- Preserved obvious imported HTML timeline, roadmap, and step-list wrappers as
  editable native Group blocks. Timeline and step items now keep stable
  importer classes, source anchors, direct `<time>` markers, and structured
  child blocks while opaque items still fall back to Classic. The clean-release
  HTML smoke fixture now verifies packaged timeline and step-list output.
- Preserved obvious imported HTML callout and card wrappers as editable native
  Group blocks. Alert/callout/notice-style wrappers now keep a stable custom
  callout class, warning/info/etc. tone hints, source anchors, and structured
  child blocks. Card-style wrappers keep their grouped structure instead of
  being flattened, while opaque children still fall back to Classic. The
  clean-release HTML smoke fixture now verifies packaged callout and card
  output.
- Added richer legacy HTML interaction fidelity. Bootstrap-style accordion
  wrappers now convert into editable native Details blocks with open state,
  anchors, and grouping preserved where possible. Obvious tabbed interfaces
  are preserved as sanitized Custom HTML blocks instead of being flattened
  into unrelated lists and paragraphs, and the clean-release HTML smoke
  fixture now verifies both paths.
- Hardened imported HTML sanitization before block inference. The shared HTML
  path now removes executable event-handler attributes, `srcdoc`,
  `javascript:`/`vbscript:` URL attributes, SVG/text HTML data URLs, and legacy
  executable CSS before serializing generated blocks or fallback markup.
  Top-level forms are preserved as sanitized Custom HTML blocks instead of
  Classic fallback, and the clean-release HTML smoke fixture now verifies form
  preservation plus removal of inline handlers.
- Added native Pullquote block inference for imported HTML. Legacy
  `blockquote.pullquote` markup and existing `figure.wp-block-pullquote`
  wrappers now serialize as `wp:pullquote` instead of plain Quote or Classic
  blocks, preserving inline citations and moving figure captions into Pullquote
  citations when needed. The clean-release HTML smoke fixture now verifies
  packaged pullquote output.
- Added native Columns block inference for obvious imported HTML grid layouts.
  Legacy wrappers such as Bootstrap-style `row`/`col-*` and existing
  `wp-block-columns` markup now become `wp:columns`/`wp:column` block markup
  when all direct column children can be converted safely. Common 12-column
  grid and legacy fraction classes are preserved as Column widths, opaque
  column contents still fall back to Classic, and the clean-release HTML smoke
  fixture now verifies packaged column output.
- Added native Details block inference for imported HTML disclosures.
  `<details>/<summary>` markup now becomes `wp:details` block markup with
  nested inferred blocks, preserving source `open`, `id` anchors, and `name`
  grouping attributes. The clean-release HTML smoke fixture now verifies the
  packaged importer preserves the Details block end to end.
- Broadened clean-release coverage for browser/admin decision resolution. The
  REST relationship mapping smoke now resolves the pending
  `map-rest-relationships:*` decision through the packaged
  `ImportAdminPage` admin API instead of bypassing it with direct store writes,
  and the release assertion verifies the admin-origin `decision.resolved`
  event before checking that the mapping is applied to the imported draft.
- Surfaced active remote REST rate-limit backoff to operators. WP-CLI status
  now prints a `Remote backoff` section with the source, HTTP status,
  retry-after value, retry timestamp, and retry URL. The browser admin
  snapshot and Tools page now expose the same active backoff summary and count
  source items that are still `processing`, so a deliberate wait no longer
  looks like a stuck import.
- Broadened clean-release REST relationship mapping coverage. The Playground
  REST smoke fixture now includes an unmapped remote author and custom
  taxonomy term, and the local clean-site smoke imports a deterministic
  WordPress HTTP fixture for the same mapping flow. Both smoke paths resolve
  the pending `map-rest-relationships:*` decision, tick the importer again,
  and assert the imported draft receives the selected local author/category,
  stores the mapping answer, records `post.relationships_mapping_applied`, and
  writes relationship-mapping idempotency.
- Hardened WordPress REST traversal and clean-release comment coverage.
  Discovered REST collection first pages that return 401/404 or other request
  failures now record `remote.wp_rest_page_unavailable` warnings and continue
  with later collections instead of failing the entire remote source after
  useful documents were already queued. The local and Playground clean release
  REST smoke fixtures now include nested comments, and the smoke assertion
  verifies sanitized imported comment content, parent mapping, importer
  metadata, and `comment.created` progress events in a disposable WordPress
  runtime.
- Added linked HTML image fidelity. Direct linked images and figure/paragraph
  image links now become native Image blocks with custom-link attributes while
  preserving `target` and `rel` on the saved anchor. The clean release HTML
  fixture now asserts linked-image block serialization in a disposable
  WordPress runtime.
- Broadened the clean release WordPress REST smoke to cover featured-media
  sideloading and prepared-document rewrite. The local clean-site smoke now
  publishes a source post with a featured image, allows the disposable
  loopback media port through WordPress safe HTTP filters, imports the REST
  source through WP-CLI, and asserts the resulting draft uses the newly
  imported attachment URL instead of the source media URL. The Playground REST
  fixture now exposes matching embedded featured-media and binary image
  responses, and the release smoke marker reports `rest_attachment_id`.
- Hardened local media reference resolution so Markdown/HTML media paths cannot
  escape the selected local import source tree. Relative `../` references and
  absolute local paths that resolve outside the import root are now stored as
  skipped media references with `media.reference_skipped_outside_source`
  warning events instead of being imported as attachments.
- Added mixed inline HTML paragraph block splitting. Paragraphs that combine
  surrounding text with obvious inline images, button-style links, or
  downloadable file links now preserve the surrounding copy as paragraph
  blocks while promoting those inline candidates to native image, buttons, and
  file blocks. The clean release HTML fixture now asserts the mixed inline
  media/button path in a disposable WordPress runtime.
- Added native HTML button and file-link inference. Obvious button-style links
  now become a `core/buttons` wrapper with a nested `core/button`, preserving
  URL, target, rel, title, and rich link text. Direct document/archive download
  links now become `core/file` blocks with download buttons while preserving
  link target/rel attributes in the saved markup. The clean release HTML
  fixture now asserts packaged button and file output.
- Added native HTML audio, video, and embed inference. Imported `<audio>` and
  `<video>` elements, including figure captions, now become WordPress media
  blocks with serialized media attributes. Known iframe providers such as
  YouTube, Vimeo, SoundCloud, and Spotify become native Embed blocks, while
  unknown iframes are preserved in Custom HTML blocks instead of Classic
  fallback. The clean release HTML fixture now asserts packaged media and
  custom HTML output.
- Added native HTML code and gallery block inference. Obvious
  `<pre><code>` snippets now become WordPress code blocks instead of
  preformatted blocks, and explicit HTML gallery containers such as
  `class="gallery"` now become gallery blocks with nested image blocks and
  normalized captions. The clean release HTML fixture now asserts packaged
  code and gallery output in a disposable WordPress runtime.
- Deepened HTML block inference for nested semantic markup and captions.
  Shared HTML conversion now unwraps obvious semantic containers such as
  `article`, `main`, `section`, and `div` when their children can become
  native blocks, normalizes image figure captions, converts table figures and
  table captions to WordPress table block captions, preserves heading `id`
  attributes as block anchors for fragment links, and keeps opaque nested
  structures in the classic fallback. The clean release HTML fixture now
  asserts image and table blocks with `wp-element-caption` captions using a
  distinct HTML media fixture so Markdown media rewrite assertions remain
  deterministic.
- Shared HTML block inference with remote imports. WordPress REST rendered
  content and single remote HTML fallback now use the same script-stripping and
  top-level block inference path as local HTML/WXR/EPUB content, recording
  `html_block_conversion` metadata instead of wrapping all remote markup in a
  classic block. The clean release smoke now includes a local HTML fixture and
  asserts structured heading, paragraph, and list blocks, while the REST smoke
  asserts native paragraph output without scripts or classic fallback.
- Added first-pass HTML block inference. Local HTML files, legacy WXR HTML,
  and EPUB spine XHTML now strip scripts and map obvious top-level elements
  such as headings, paragraphs, lists, quotes, tables, separators, and images
  to native WordPress blocks, while preserving a classic-block fallback for
  opaque markup. Local HTML source-item metadata now records whether the
  conversion was structured, mixed, or classic.
- Broadened the clean release smoke to cover corrupt PDF structure
  diagnostics. The packaged smoke now imports a readable PDF with a compressed
  object stream marker and missing `%%EOF` trailer, asserts that the draft
  persists, verifies durable `pdf_structure_*` metadata, and checks that a
  `document.pdf_structure_warning` event is recorded in a clean WordPress
  runtime.
- Added malformed/corrupt PDF structure diagnostics. The first-pass PDF reader
  now records missing header/trailer markers, malformed stream markers,
  compressed stream decode failures, and PDF object streams that the built-in
  parser cannot expand. These diagnostics persist as `pdf_structure_*`
  metadata, emit `document.pdf_structure_warning` events, and surface in
  WP-CLI/admin PDF status hints for both imported and failed PDF items.
- Hardened embedded PDF media diagnostics for adversarial media payloads.
  Large directly embedded JPEG streams are now detected by an offset-based PDF
  stream scanner instead of a backtracking regex, skipped before cache writes
  when they exceed the 8 MiB per-asset limit, and persisted with explicit
  limit metadata. PDFs with more than 10 embedded JPEG streams now keep the
  first 10 queued assets while recording a partial extraction diagnostic,
  warning event, and operator-facing limit hint for the skipped streams.
- Broadened the clean release smoke to cover layout-aware PDF table
  conversion. The packaged smoke now imports a textless PDF through the
  configured external text helper, asserts that fixed-width table text becomes
  a WordPress table block, verifies durable `pdf_table_*` metadata, and checks
  that a `document.pdf_table_blocks` progress event is recorded in a clean
  WordPress runtime.
- Added first-pass layout-aware PDF table conversion. External PDF text
  extraction now preserves horizontal spacing so commands such as `pdftotext
  -layout` can feed simple fixed-width, tab-separated, or pipe-separated table
  runs into WordPress table blocks. Converted PDFs record
  `pdf_table_*` metadata, emit a `document.pdf_table_blocks` event, and keep a
  layout warning when complex columns, merged cells, or vector-only tables may
  still need review.
- Broadened the clean release smoke to cover unsupported embedded PDF media
  diagnostics. The packaged smoke now imports a text-bearing PDF with a
  `JPXDecode` image stream, asserts the draft still persists without a bogus
  PDF attachment rewrite, verifies `pdf_unsupported_embedded_media_*`
  metadata, and checks that a `media.pdf_asset_unsupported` warning event is
  recorded in a clean WordPress runtime.
- Added structured diagnostics for unsupported embedded PDF media streams.
  PDF processing now records skipped embedded image filters and skip reasons
  such as `JPXDecode`, `FlateDecode`, extraction limits, and bounded file-size
  limits, emits a warning event, and surfaces a more actionable PDF status hint
  when non-JPEG or otherwise unsupported streams cannot be extracted by the
  first-pass media path.
- Added first-pass embedded PDF JPEG extraction. PDF processing now extracts
  directly embedded `DCTDecode` image streams into the importer-managed cache,
  queues them as `pdf-embedded-asset` media references, appends image blocks to
  the prepared PDF document, and lets the existing attachment importer rewrite
  those blocks to local attachment URLs before draft persistence. The clean
  release smoke now asserts the packaged PDF fixture imports its embedded image
  attachment, rewrites the draft content, and preserves extraction metadata.
- Added first-pass PDF fidelity diagnostics. PDF processing now records bounded
  stream/text-operator counts plus structured hints when embedded image
  references or table/vector drawing signals are detected. These diagnostics
  are stored on source items and prepared documents, and WP-CLI/admin PDF/OCR
  status summaries now surface the hints so operators can see when normalized
  text was imported but embedded PDF media, table structure, columns, or richer
  layout were not preserved.
- Added adversarial external PDF helper diagnostics and release-smoke coverage.
  Failed helper commands now persist bounded `pdf_external_text_error`
  metadata, misconfiguration/unavailable/empty-output paths include structured
  operator diagnostics, OCR failures mirror that with `pdf_ocr_error`, and the
  failed source-item message now surfaces the external helper failure when OCR
  is unavailable. The clean release smoke now includes a second textless PDF
  whose helper deliberately rejects the input and asserts the failed source item
  preserves the expected external-helper diagnostics while the rest of the
  import completes.
- Broadened the clean release smoke to cover configured external PDF text
  extraction. The smoke now imports a second textless PDF, installs a
  smoke-only mu-plugin that configures `universal_importer_pdf_text_command`,
  and asserts both the persisted draft content and durable
  `pdf_text_engine: external` / `pdf_external_text_status: succeeded`
  metadata in local and Playground smoke fixtures.
- Added an optional external PDF text extraction step before OCR. Operators can
  configure `UNIVERSAL_IMPORTER_PDF_TEXT_COMMAND`, for example
  `pdftotext -layout {input} {output}`, with bounded timeout/output handling.
  Successful extraction is recorded with `pdf_text_engine: external`; failures
  persist `pdf_external_text_status` diagnostics and still fall through to OCR
  when configured.
- Added explicit PDF/OCR operator status visibility. WP-CLI `status` and the
  browser admin session snapshot now summarize recent PDF items, extraction
  engine, OCR status, failure message, and OCR configuration hint so scanned
  PDF problems are visible without digging into raw source-item metadata.
- Added retry-safe remote REST rate-limit handling. WordPress HTTP responses
  with HTTP 429, or HTTP 503 plus `Retry-After`, now raise a typed retryable
  diagnostic with a bounded parsed delay. Remote traversal stores
  `remote_rate_limit` backoff metadata, emits a warning event, preserves REST
  cursors, and defers additional requests until the stored retry time instead
  of marking the source failed or skipping the collection.
- Added adversarial WordPress REST payload-shape diagnostics. REST collection
  endpoints that return associative JSON objects, such as HTTP-200 REST error
  payloads, now persist bounded `remote_rest_page_warnings`, emit a warning
  event with a compact payload-shape summary, and continue with the next
  collection instead of treating object fields as collection items. REST
  comment endpoints with the same non-list shape now mark comment staging
  complete for that document with an observable warning so post import can
  continue. Runner coverage now verifies malformed collection and comment
  payload recovery.
- Added adversarial remote traversal diagnostics and coverage for protected,
  malformed, redirecting, and partially unavailable WordPress REST sources.
  Later REST pagination failures now persist bounded
  `remote_rest_page_warnings` metadata and emit a
  `remote.wp_rest_page_unavailable` event before traversal continues with the
  next collection. Authenticated HTTP redirects now fail with guidance to
  import the final canonical URL instead of silently exposing credentials to a
  redirect chain. Runner tests now cover protected-source auth diagnostics,
  malformed REST index fallback to the requested HTML document, and late
  pagination failure observability.
- Hardened alternate WordPress REST API root discovery against hostile
  cross-host links. `RemoteUrlSourceWalker` now accepts `api.w.org` roots from
  HTTP `Link` headers or HTML `<link>` elements only when the discovered REST
  root is on the same host as the operator-provided source URL; cross-host
  candidates are ignored and traversal falls back to importing the requested
  source document. Runner coverage now verifies that malicious header and HTML
  discovery links do not pivot requests to another host.
- Added host-scoped authentication for remote URL and WordPress REST sources.
  `WordPressRemoteContentFetcher` now reads exact allowed hosts from
  `UNIVERSAL_IMPORTER_REMOTE_AUTH_HOSTS` and sends either bearer-token
  credentials from `UNIVERSAL_IMPORTER_REMOTE_BEARER_TOKEN` or Basic
  credentials from `UNIVERSAL_IMPORTER_REMOTE_BASIC_USER` plus
  `UNIVERSAL_IMPORTER_REMOTE_BASIC_PASSWORD` only to matching hosts. Remote
  401/403 responses now include actionable configuration guidance, and unit
  coverage verifies auth headers are not sent to unlisted hosts.
- Added Playground-compatible WordPress REST traversal coverage to the packaged
  release smoke. The Playground blueprint now installs a temporary mu-plugin
  that serves a deterministic remote REST fixture through WordPress'
  `pre_http_request` hook, imports
  `https://playground-rest-smoke.example/wp-json/` through the plugin's normal
  remote REST traversal path, and asserts that the REST page becomes a
  script-stripped `wp-rest` draft with importer source metadata. The Playground
  runner now treats successful Blueprint execution as the assertion contract
  instead of requiring `runPHP` echo markers that current
  `@wp-playground/cli` no longer surfaces reliably.
- Added local WordPress REST traversal coverage to the packaged clean-site
  release smoke fallback. The local WP-CLI/private-MariaDB smoke now creates a
  published source page in the disposable WordPress install, serves that install
  over a loopback PHP HTTP server with a rewrite-independent `/wp-json/` router,
  imports the source through the plugin's remote REST traversal path, and
  asserts that the REST page becomes a script-stripped `wp-rest` draft with
  importer source metadata.
- Added PDF coverage to the packaged clean-site release smoke. The smoke now
  creates a small text-bearing PDF in the mixed source tree, imports it through
  the installed release zip, and asserts that native PDF text extraction
  produces a draft page with expected heading, paragraph content, PDF document
  format metadata, and importer source metadata in the disposable WordPress
  runtime.
- Added EPUB coverage to the packaged clean-site release smoke. The smoke now
  creates a minimal two-chapter EPUB with navigation metadata and an internal
  chapter link, imports it through the installed release zip, and asserts that
  both spine entries become script-stripped draft pages with importer metadata
  and a resolved internal link in the disposable WordPress runtime.
- Added WXR coverage to the packaged clean-site release smoke. The smoke now
  creates a minimal WordPress export fixture in the mixed source tree, imports
  it through the installed release zip, and asserts that the WXR post becomes a
  script-stripped draft page with importer source metadata in the disposable
  WordPress runtime.
- Added zip-wrapped Markdown coverage to the clean-site release smoke. The
  packaged plugin smoke now creates an archive inside the mixed source tree,
  runs enough bounded continuation ticks to expand and process it, and asserts
  that the archive-contained Markdown becomes a draft page with importer source
  metadata in the disposable WordPress runtime.
- Broadened the clean-site release smoke from a single Markdown file to a
  mixed local input tree. The packaged plugin smoke now imports a directory
  containing Markdown plus a local PNG reference, runs bounded continuation
  ticks, asserts the local image was imported as a WordPress attachment, and
  verifies the persisted draft content was rewritten from the source relative
  media path to the attachment URL.
- Strengthened the clean-site release smoke so it keeps running bounded
  continuation ticks until the sample Markdown import is persisted as a draft
  page, then asserts the imported title, block markup, body text, and importer
  post metadata in the disposable WordPress runtime.
- Known-good tag `known-good/release-smoke-local-20260513` covers the current
  release packaging and clean-site smoke baseline: production-dependency zip
  builds, Playground smoke generation, local WP-CLI/private-MariaDB fallback,
  release activation, importer table verification, CLI import session creation,
  and one continuation tick.
- Added a secondary clean-site release activation smoke runtime:
  `composer smoke:release` now defaults to `--runtime=auto`, tries the
  existing WordPress Playground smoke first, and falls back to a local
  WP-CLI/private-MariaDB WordPress install when Playground infrastructure
  cannot fetch, boot, or expose assertion markers. The local runtime installs
  the packaged zip, activates it, verifies importer tables, creates an import
  session, and runs an importer tick.
- Fixed release packaging so normal Composer stderr/progress output from the
  no-dev production dependency install no longer fails otherwise successful
  release builds.
- Added an automated release activation smoke path using WordPress Playground:
  `composer smoke:release` now builds or accepts a packaged zip, creates a
  self-contained Blueprint bundle, installs and activates the zip in a clean
  disposable WordPress site, checks importer schema tables, and exercises the
  plugin's WP-CLI import/tick commands with actionable runtime diagnostics.
- Fixed the real WP-CLI `tick` synopsis so internal runner failure controls
  such as `--max-ticks` are accepted by WP-CLI command parsing before dispatch.
- Added `composer build:release` release automation with version metadata
  validation, clean-tree and preflight checks, `.distignore`-driven staging,
  production dependency installation in the staging directory, and release zip
  unit coverage.
- Added operator and contributor documentation for usage, architecture,
  recovery behavior, and release packaging, plus an initial WordPress.org-style
  `readme.txt` and `.distignore` release exclusion list.
- Added durable source item queue persistence in schema version `2`.
- Added local filesystem discovery for files and directories through the shared
  import runner, with progress counters and source-discovery checkpoints.
- Added unit coverage for source item persistence, local directory traversal,
  runner progress updates, and schema generation.
- Added first-pass document item classification for Markdown, text, and HTML
  files, including script stripping, initial block markup metadata, unsupported
  file diagnostics, and idempotency records for prepared documents.
- Replaced the document processor's old 1 MiB inline file read with chunked
  `wp-php-toolkit/bytestream` reads for Markdown, text, and HTML, including
  script stripping that survives tags split across stream chunks.
- Added a durable prepared-document staging table in schema version `3`, with
  store APIs and processor wiring so block markup no longer lives inside source
  item metadata.
- Added idempotent prepared-document persistence into WordPress draft pages,
  including post metadata lookups for crash-gap recovery and unit coverage for
  create, update, retry, unavailable API, and write-failure paths.
- Added a hidden post/idempotency crash simulation control plus adversarial
  recovery tests proving a retry reuses the draft page written before the
  idempotency record was persisted.
- Added the first browser/admin import surface under Tools > Universal Importer,
  with secure AJAX create, keepalive, and abort handlers backed by the shared
  durable session store and continuation runner.
- Added browser/admin decision resolution controls, including first-party
  domain confirmation, generic JSON answers for future decisions, observable
  `decision.resolved` events, and continuation scheduling after resolution.
- Added richer browser/admin progress snapshots and rendering for source item
  status counts, recent source items, prepared document counts/details, and
  persisted post counts.
- Added adversarial local import fixtures and runner-level coverage for missing
  source paths, unsupported files, duplicate retries, and timeout-interrupted
  ticks, including a missing-path completion diagnostic fix.
- Added first-party URL-domain inference from prepared Markdown/text/HTML
  documents, with pending confirmation decisions that pause WordPress post
  persistence until confirmed.
- Added confirmed first-party URL rewriting for prepared documents before draft
  post persistence, preserving paths, queries, and fragments while leaving
  outside and lookalike domains untouched.
- Added durable media-reference queueing in schema version `4`, including
  detection for local Markdown/HTML media paths and confirmed first-party media
  URLs while ignoring outside, lookalike, script, and missing references.
- Added the first local media attachment importer, including WordPress media
  gateway persistence, attachment idempotency records, recovery by attachment
  metadata lookup, prepared-document rewrites to attachment URLs before post
  persistence, and unit coverage for success, retry, unavailable APIs, and
  write-failure diagnostics.
- Added a hidden media attachment/idempotency crash simulation control plus
  adversarial recovery tests proving a retry reuses the attachment written
  before the idempotency record was persisted.
- Added confirmed first-party remote media sideloading through WordPress'
  download/sideload APIs, including attachment reuse after crash-gap recovery,
  prepared-document rewrites to local attachment URLs, and runner coverage that
  persists draft posts only after remote media has been imported.
- Added zip and nested zip source traversal through the shared runner, including
  durable archive-entry source items, bounded extraction into a resumable cache,
  unsafe-path and oversized-entry diagnostics, and runner coverage for archive
  documents, unsupported entries, and nested archives across ticks.
- Added first-pass WXR processing through `wp-php-toolkit/data-liberation`:
  `.wxr`/`.xml` exports now parse post entities into durable prepared
  documents, strip scripts, preserve existing block markup, use classic blocks
  for legacy HTML, skip attachment/revision/menu-item records, and store a WXR
  cursor so large exports resume across runner ticks without double-counting
  the entity repeated by the upstream cursor.
- Added first-pass EPUB processing: `.epub` archives now read
  `META-INF/container.xml`, parse the OPF manifest/spine without network access,
  stage XHTML spine entries as durable prepared classic-block documents, strip
  scripts, preserve absolute URL-domain metadata, store a spine cursor for large
  books, and record actionable diagnostics for malformed or unsafe packages.
- Added embedded EPUB asset extraction: manifest media referenced from XHTML
  spine entries is extracted into the importer-managed cache, queued through the
  existing media attachment pipeline, rewritten to local attachment URLs before
  draft persistence, and cleaned up with the session cache. Internal relative
  spine links are rewritten to stable importer anchors and recorded in prepared
  document metadata for later post-link resolution.
- Added EPUB internal permalink resolution: after EPUB spine drafts are
  persisted, recorded cross-spine anchor placeholders are resolved to imported
  draft permalinks, original EPUB fragments are preserved when present, the
  prepared document hash is updated, and the existing idempotent post persister
  updates affected drafts in the same runner tick.
- Added EPUB navigation and TOC metadata staging: EPUB 3 `nav` documents and
  EPUB 2 NCX manifests are parsed into bounded flat TOC entries, linked back to
  matching spine indexes/fragments, stored on the EPUB source item and matching
  prepared spine documents, and surfaced through observable staging/failure
  events without blocking chapter import.
- Surfaced staged EPUB table-of-contents metadata in operator views: WP-CLI
  status output and the browser/admin status snapshot now summarize EPUB TOC
  source, archive entry, entry count, sample labels, target paths/fragments, and
  non-blocking TOC parse warnings.
- Added first-pass PDF processing: `.pdf` files now use bounded byte-stream
  reads, decode common uncompressed and Flate-compressed text streams, stage
  extracted text as prepared paragraph blocks, surface URL-domain metadata, and
  fail image-only or unsupported PDFs with durable diagnostics instead of empty
  draft posts.
- Added optional scanned-PDF OCR fallback: image-only PDFs can use an
  operator-configured local `UNIVERSAL_IMPORTER_PDF_OCR_COMMAND` command
  template with `{input}`, optional `{output}` sidecar text, `{scratch}` output
  PDF path, bounded stdout/stderr capture, timeout enforcement, and durable
  success/failure metadata. Missing or failing OCR remains a clear source-item
  diagnostic instead of producing empty posts.
- Added first-pass GitHub repository traversal: `github.com/owner/repo` and
  branch/tag URLs are downloaded through the GitHub zipball API into a durable
  importer cache, queued as zip source items, and then handed to the existing
  archive/document/post pipeline with observable failure diagnostics.
- Added first-pass remote URL and WordPress REST traversal: non-GitHub HTTP(S)
  sources now detect `wp/v2` support through the REST index, page through public
  pages/posts into durable prepared documents, resume from stored cursors, and
  fall back to a single script-stripped remote HTML document when REST is not
  available.
- Added REST post type discovery for remote WordPress sites through
  `wp/v2/types`, including custom public collection bases, durable endpoint
  cursors, attachment/private-type filtering, and fallback to pages/posts when
  type discovery is unavailable.
- Added remote WordPress REST featured-media handling: collection requests now
  ask for `wp:featuredmedia` embeds, fall back to individual media entities
  when needed, stage featured images as image blocks, and reuse the existing
  first-party confirmation plus remote media sideload pipeline before draft
  posts are written.
- Added alternate WordPress REST root discovery for remote sites: the importer
  now validates API roots advertised through `Link: <...>; rel="https://api.w.org/"`
  headers and HTML `<link rel="https://api.w.org/">` elements, including
  plain-permalink `?rest_route=/` roots, before falling back to single-page
  HTML import.
- Added first-pass remote WordPress REST relationship staging: collection
  requests now embed author, taxonomy term, and featured-media relations; the
  importer stores normalized author and term metadata on prepared documents and
  source items so later post persistence can restore relationships.
- Added first-pass REST relationship persistence onto draft posts: staged
  remote authors are mapped to existing local users when possible, staged terms
  are matched or created for existing local taxonomies, original relationship
  metadata is retained on post meta, and partial mappings record warning events
  for operator review.
- Added REST relationship operator follow-up visibility: WP-CLI and the admin
  status view now summarize recent partial author/taxonomy mappings, and each
  affected draft gets a durable mapping decision with remote metadata,
  diagnostics, and a structured answer template.
- Added idempotent application of resolved REST relationship mapping decisions:
  continuation ticks now apply operator-selected local authors and taxonomy
  terms to already-imported drafts, record retryable diagnostics for incomplete
  mappings, and avoid reapplying successful answers.
- Added first-pass remote WordPress REST comment staging: imported REST
  documents now enqueue their public comment collections, fetch comment pages
  through a durable cursor, normalize comment author/content/date/parent/source
  metadata onto prepared documents and source items, and leave imported draft
  lookup keys ready for local comment persistence.
- Added idempotent remote WordPress REST comment persistence: staged comments
  are written as local WordPress comments after their draft posts exist, remote
  parent ids are mapped to local comment ids, importer metadata is stored on
  comments for recovery, and a hidden comment/idempotency crash simulation
  proves retries reuse comments written before the idempotency record.
- Added richer WXR entity staging: streaming WXR author, category/tag/term,
  postmeta, and comment entities now enrich prepared WXR documents; staged WXR
  authors/terms reuse the existing post relationship pipeline, staged WXR
  comments reuse the existing idempotent local comment persistence path, and
  script tags are stripped from WXR comment content before persistence.
- Added idempotent WXR postmeta persistence after draft post creation, with a
  separate `postmeta:<source_item_key>` recovery record, observable applied/
  deferred/failed events, and conservative skipping for volatile or importer-
  owned keys such as `_edit_lock`, `_edit_last`, `_thumbnail_id`, and
  `_universal_importer_*`.
- Added first-pass WXR attachment semantics: attachment post entities now queue
  candidate first-party media references for confirmation and sideloading, and
  staged `_thumbnail_id` postmeta is remapped idempotently to the imported local
  attachment once available.
- Added WXR attachment parent restoration: imported WXR media references now
  remap `wp:post_parent` to the matching imported draft post, defer with
  observable diagnostics while the parent is missing, and record
  `attachment-parent:<reference_key>` idempotency records for safe retries.
- Added conservative WXR postmeta attachment-reference remapping: before staged
  postmeta is written, attachment-oriented scalar and PHP-serialized values now
  remap imported WXR attachment ids plus source attachment URLs to their local
  attachment ids/URLs, and defer with actionable diagnostics while referenced
  media is still queued.
- Added conservative WXR post/page-reference remapping in staged postmeta:
  post/page-shaped scalar and PHP-serialized values now remap to imported local
  draft ids, defer while referenced WXR posts are prepared or still streaming,
  and avoid author/user/term IDs plus arbitrary numeric prose.
- Added retry-safe WXR attachment metadata persistence: attachment post titles,
  captions/excerpts, descriptions, alt text, source attached-file paths, and
  original attachment metadata are staged on WXR media references and applied
  to imported local attachments with `attachment-metadata:<reference_key>`
  idempotency records and observable diagnostics.
- Moved archive extraction and GitHub repository zipball caches behind an
  importer-managed cache directory. In WordPress runtime the cache now lives
  under the uploads base directory as `universal-importer-cache`, source item
  metadata records cache namespace/path diagnostics, upload-directory failures
  are reported as durable GitHub source item failures, and a cleanup action can
  remove importer-owned session cache files.
