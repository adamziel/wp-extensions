# Recovery Model

The importer is designed to continue after PHP timeouts, memory pressure,
process crashes, duplicate cron events, repeated browser keepalive requests,
and command reruns.

## Sessions And Locks

Each import has a stable session id, status, progress object, checkpoint, and
source. Runner ticks only process pending or running sessions.

Before doing work, the runner acquires an expiring lock for the session. If
another worker owns the lock, the tick records `session.locked` and skips the
session. Expired locks can be replaced by later ticks.

Locks are released in a `finally` path whenever PHP stays alive long enough to
handle the exception. If PHP dies before release, the expiration timestamp lets
the next worker continue later.

The runner refreshes the held lock between bounded phases such as source
walking, document processing, URL decisions, media import, post persistence,
postmeta, comments, relationship mapping, and menu persistence. A lock cannot
be refreshed while PHP is inside one blocking call that does not return control
to the runner, such as a WordPress gateway call, HTTP request, archive helper,
OCR/text helper, or filesystem operation. Those calls must stay bounded by
their own limits, timeouts, or retryable failure paths; if PHP exits during one
of them, the stale-lock TTL and idempotency metadata are the recovery boundary.

PDF first-pass structure diagnostics run as their own durable scan phase before
embedded-media and text extraction. The built-in parser only inspects PDFs at
or below the 16 MiB first-pass limit; oversized PDFs fail before structure,
embedded-media, text, OCR, or operator-configured helper work. Within the
limit, structure diagnostics, large native text scans, and embedded-media scans
store byte cursors between runner ticks. A real PHP exit after the structure
cursor is persisted is covered by the hidden fatal simulation control and
recovers through the stale-lock TTL.

## Checkpoints

The session checkpoint records a coarse cursor such as
`source-discovery:queued` or `source-discovery:complete`. Individual source
items, WXR cursors, EPUB spine indexes, REST cursors, media references,
prepared documents, and idempotency records carry finer-grained resume state.

This layered model lets the runner resume at the durable item that still needs
work instead of replaying the whole import.

## Idempotency

Writes to WordPress use deterministic idempotency keys. The store records the
resource type and resource id after successful writes. Retries look up the
idempotency row before writing again.

Several paths also recover from the crash gap where WordPress accepted the
write but the importer crashed before recording the idempotency row:

- Draft posts are recovered by importer-owned post metadata.
- Attachments are recovered by importer-owned attachment metadata.
- Comments are recovered by importer-owned comment metadata.

The next tick records the missing idempotency row and continues.

## Operator Decisions

Some work must pause until an operator chooses a safe mapping:

- First-party source domains must be confirmed before URL rewriting,
  media queueing, post persistence, comment persistence, and postmeta
  persistence continue.
- REST relationship mapping decisions can provide local author and taxonomy
  targets when automatic matching is incomplete.

Decisions are durable rows. WP-CLI and the admin page can resolve them, and
resolution schedules another continuation tick.

## Continuation Paths

The same `ImportRunner` is used by:

- WP-Cron through `universal_importer_continue_imports`.
- WP-CLI through `wp universal-importer tick`.
- The browser keepalive endpoint on Tools > Universal Importer.

This keeps recovery behavior consistent across unattended cron, command-line
testing, and a user watching the admin page.

## Failure Diagnostics

The runner writes progress events for skips, errors, blocked work, deferred
relationships, failed source items, OCR failures, REST failures, archive
diagnostics, and mapping warnings. Status views show recent events and the
most important pending decisions.

Source items, prepared documents, and media references also store metadata
about their own state, including format, cursor, cache path, OCR engine/status,
TOC summaries, and source relationship data.

Transient GitHub archive download failures are stored as failed source items
with the original error. A later continuation tick, such as one scheduled by
`wp universal-importer resume <session-id>`, retries the GitHub download and
records `github.archive_retrying` before replacing the failed item with the
downloaded archive when the retry succeeds.

## Hidden Failure Controls

The WP-CLI `tick` command accepts hidden options used by tests:

```bash
wp universal-importer tick import_... --simulate-timeout
wp universal-importer tick import_... --simulate-crash
wp universal-importer tick import_... --simulate-memory-pressure=1048576
wp universal-importer tick import_... --simulate-post-idempotency-crash
wp universal-importer tick import_... --simulate-media-idempotency-crash
wp universal-importer tick import_... --simulate-comment-idempotency-crash
wp universal-importer tick import_... --simulate-fatal-exit
wp universal-importer tick import_... --simulate-fatal-after-markdown-cursor
wp universal-importer tick import_... --simulate-fatal-after-wxr-cursor
wp universal-importer tick import_... --simulate-fatal-after-epub-spine-cursor
wp universal-importer tick import_... --simulate-fatal-after-zip-entry-cursor
wp universal-importer tick import_... --simulate-fatal-after-rest-page-cursor
wp universal-importer tick import_... --simulate-fatal-after-github-tree-cursor
wp universal-importer tick import_... --simulate-fatal-after-pdf-structure-cursor
wp universal-importer tick import_... --simulate-fatal-after-post-write
wp universal-importer tick import_... --simulate-fatal-after-media-write
wp universal-importer tick import_... --simulate-fatal-after-comment-write
wp universal-importer tick import_... --max-ticks=1
```

These controls are intentionally not part of the public command docs. They
exist so automated tests can prove retries resume safely after controlled
interruptions.

## Operational Recovery Playbook

1. Run `wp universal-importer status <session-id>`.
2. Resolve any pending decisions.
3. If the session is paused or failed and the diagnostic is understood, run
   `wp universal-importer resume <session-id>`.
4. If cron is disabled or delayed, run `wp universal-importer tick <session-id>`.
5. If the source cannot be fixed or the import is no longer wanted, run
   `wp universal-importer abort <session-id>`.

Do not delete importer tables or cache files to recover a normal interrupted
import. The durable state is what allows the next tick to continue correctly.
