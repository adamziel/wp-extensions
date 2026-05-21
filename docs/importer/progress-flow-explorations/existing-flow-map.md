# Existing Universal WordPress Importer Flow Map

This maps the current Universal WordPress Importer wp-admin flows from the code and nearby docs. It is docs-only; plugin runtime files were read but not edited.

Browser screenshot note: no live wp-admin screenshots were captured because this workspace does not include a running WordPress site or `wp-env` setup. The map is based on concrete code references, the existing unit/browser harnesses, and docs. Static docs could be opened locally, but that would not verify the real admin runtime.

## Evidence Base

- Admin page and AJAX surface: `universal-wordpress-importer/src/Admin/ImportAdminPage.php:33-45`, `:113-121`, `:1405-1493`, `:2908-3030`.
- Shared session and runner: `src/Import/ImportSession.php:15-21`, `:118-132`; `src/Import/ImportRunner.php:167-180`, `:189-247`, `:249-300`, `:414-429`.
- Cron and CLI share the same runner model: `src/Plugin.php:32-45`, `:64-79`; `src/Cli/ImportCommand.php:121-176`, `:210-250`.
- Admin upload, GitHub browse, keepalive, abort, decisions, dashboard, and review links: `ImportAdminPage.php:241-312`, `:323-346`, `:366-414`, `:439-520`, `:530-595`, `:623-637`, `:4058-4332`, `:4375-4523`.
- URL decisions and rewriting: `src/Import/ImportUrlInference.php:39-94`, `:103-222`, `:276-311`; `src/Import/ImportUrlRewriter.php:55-156`, `:181-249`, `:257-260`.
- GitHub import implementation: `src/Import/GitHubRepositorySourceUrl.php:20-94`, `:102-172`; `src/Import/GitHubRepositorySourceWalker.php:71-117`, `:128-223`, `:365-417`.
- Page persistence and relationship warning decisions: `src/Import/WordPressPostGateway.php:98-150`; `src/Import/ImportPostPersister.php:219-235`, `:344-360`; `src/Import/ImportRelationshipMappingDecision.php:13-24`, `:59-88`; `src/Import/ImportRelationshipMappingApplier.php:50-74`, `:107-155`.
- Docs inspected for product intent and stale/conflicting language: `README.md`, `docs/usage.md`, `docs/architecture.md`, `docs/recovery-model.md`, `readme.txt`, and public docs under `docs/importer/`.

## Runtime Surface

The admin entry is Tools > Universal Importer, registered with page slug `universal-wordpress-importer` and capability `manage_options` (`ImportAdminPage.php:129-136`). The practical admin URL is:

```text
wp-admin/tools.php?page=universal-wordpress-importer
```

The admin screen is rendered as inline PHP, CSS, and JavaScript (`ImportAdminPage.php:660-690`, `:1405-1524`, `:1524-2899`). Browser actions use authenticated `admin-ajax.php` requests with the `universal_importer_admin` nonce and `manage_options` capability (`ImportAdminPage.php:1568-1607`, `:3582-3587`). There are no custom REST routes for this admin flow.

| Browser action | AJAX action | Main inputs | Code reference |
| --- | --- | --- | --- |
| Start from URL/server path | `universal_importer_create_session` | `source`, `confirmed_domains`, `url_rewrite_mode`, `import_as_drafts`, `dry_run` | `ImportAdminPage.php:195-226`, `:2769-2802`, `:2908-2924` |
| Start from browser files/folder | `universal_importer_upload_session` | `files[]`, `paths[]`, URL/draft/dry-run options | `ImportAdminPage.php:241-312`, `:2781-2792`, `:2931-2948` |
| Browse GitHub directories | `universal_importer_github_directories` | `source` | `ImportAdminPage.php:323-346`, `:1680-1711`, `:3019-3030` |
| Keep active import moving | `universal_importer_keepalive` | `session_id` | `ImportAdminPage.php:366-414`, `:2747-2767`, `:2955-2967` |
| Abort | `universal_importer_abort_session` | `session_id` | `ImportAdminPage.php:439-468`, `:2888-2893`, `:2974-2985` |
| Resolve pending decision | `universal_importer_resolve_decision` | `session_id`, `decision_key`, URL choice or JSON answer | `ImportAdminPage.php:480-520`, `:2840-2887`, `:2993-3011`, `:5040-5068` |

## Shared Import Model

Every start path creates an `ImportSession` with a stable id, source descriptor, status, progress object, checkpoint, and dry-run flag. New sessions start as `pending`; runner ticks move them to `running`; terminal statuses include `done` and `aborted`; `failed` and `paused` are non-terminal but not runnable until resumed (`ImportSession.php:15-21`, `:118-132`; `ImportRunner.php:189-206`).

The same `ImportRunner` powers WP-Cron, WP-CLI, and browser keepalive. A tick acquires a lock, marks pending sessions running, advances bounded phases, refreshes the lock between phases, records events, saves progress, schedules another tick when runnable work remains, and releases the lock (`ImportRunner.php:167-180`, `:208-247`, `:249-300`, `:414-429`, `:455-545`).

The admin dashboard derives a six-stage checklist from durable session state (`ImportAdminPage.php:4375-4523`):

1. Read source
2. Prepare content
3. URL treatment
4. Import media
5. Write pages
6. Finish

Technical details expose source item counts, prepared documents, persisted posts, comments, media references, remote backoff, PDF/OCR notes, EPUB TOCs, recent source items, prepared documents, and media references (`ImportAdminPage.php:2496-2593`, `:3283-3471`).

## Flow: URL Or Server Path Import

1. The user opens Tools > Universal Importer (`ImportAdminPage.php:129-136`).
2. Source shortcut buttons are shown for GitHub repo, WordPress site, Feed or OPML, Server path, and Browser folder (`ImportAdminPage.php:1409-1430`). Except Browser folder, they only change the source input placeholder and focus the field (`ImportAdminPage.php:2116-2130`).
3. The user enters a server path, local file/archive path, WordPress site/REST URL, feed/OPML URL, remote page URL, or GitHub URL in the single `source` input (`ImportAdminPage.php:1431-1435`).
4. The user chooses URL treatment, optional old-site domains, optional Import as drafts, and optional Dry run (`ImportAdminPage.php:1460-1488`).
5. Submit posts `universal_importer_create_session` (`ImportAdminPage.php:2769-2802`, `:2908-2924`).
6. The server validates non-empty `source`, creates a pending session, records `session.created`, records a GitHub queued event when the source parses as GitHub, stores initial URL/post-status decisions, schedules continuation, and returns a status snapshot (`ImportAdminPage.php:195-226`, `:3829-3851`, `:3862-3899`, `:3962-3977`).
7. The browser renders the returned card, shows "Import started.", starts a 5-second keepalive interval, and immediately calls keepalive (`ImportAdminPage.php:2794-2799`).

Runner source handling then depends on the source string:

- Local paths are walked by `LocalFilesystemSourceWalker` (`ImportRunner.php:241-247`; `LocalFilesystemSourceWalker.php:41-92`).
- Discovered ZIP files are expanded into cache-backed child source items (`ImportRunner.php:249-251`; `ZipArchiveSourceWalker.php:63-92`, `:104-218`).
- GitHub repository URLs are handled by `GitHubRepositorySourceWalker` (`ImportRunner.php:243-245`; `GitHubRepositorySourceWalker.php:71-117`).
- Remote HTTP(S) URLs skip GitHub and try WordPress REST first, then fall back to a single remote document path when REST is unavailable (`RemoteUrlSourceWalker.php:69-80`, `:184-223`).

## Flow: Browser Upload

Browser upload starts on the same form but bypasses the source field once files are selected.

1. The user chooses files, chooses a folder, clicks the Browser folder shortcut, or drops files/folders onto the upload area (`ImportAdminPage.php:1426-1429`, `:1443-1457`, `:2308-2364`, `:2804-2838`).
2. The inline script stores selected `File` objects in `browserFiles`; while that array is non-empty, `sourceInput.required` becomes false, Clear selection is enabled, and the GitHub picker is hidden (`ImportAdminPage.php:1554-1560`, `:1623-1647`, `:2094-2114`).
3. The selected-file preview is a keyboard-navigable tree. It renders up to 120 files, sorts directories before files, supports expand/collapse, arrow keys, Home/End, and prefix search (`ImportAdminPage.php:1897-2005`, `:2007-2306`).
4. Submit switches from `universal_importer_create_session` to `universal_importer_upload_session`; the payload includes `files[]` and parallel `paths[]`, plus URL/draft/dry-run options. A typed `source` is not sent in this path (`ImportAdminPage.php:2781-2792`).
5. The server rejects empty uploads, more than 500 files, more than 128 MiB, duplicate normalized paths, parent-directory segments, unsafe empty path segments, and unreadable upload rows (`ImportAdminPage.php:241-284`, `:3790-3820`, `:3646-3671`).
6. Accepted files are staged under importer cache at `browser-uploads/<session-id>/tree`, then the session source becomes that staged local directory (`ImportAdminPage.php:250-251`, `:287-312`, `:3926-3952`).
7. The rest of the import uses the same local filesystem runner path as server-side directory imports.

## Flow: GitHub Browse And Import

GitHub directory browsing is a source-selection helper, not a separate import type.

1. The picker appears only when the input looks like `https://github.com/<owner>/<repo>` and no browser files are selected (`ImportAdminPage.php:1619-1647`).
2. Choose directory opens a modal and posts the source URL to `universal_importer_github_directories` (`ImportAdminPage.php:1680-1711`, `:3019-3030`).
3. The server parses supported GitHub URLs, resolves `HEAD` to the default branch through the repository API, and fetches a recursive GitHub tree API response (`ImportAdminPage.php:323-346`, `:3708-3717`; `GitHubRepositorySourceUrl.php:20-94`, `:143-155`).
4. Slash-containing `/tree/<ref>/<path>` URLs are tried as candidate branch/path splits, so `.../tree/trunk/docs` can resolve to ref `trunk` and path `docs` (`GitHubRepositorySourceUrl.php:57-70`, `:102-134`; `ImportAdminPage.php:338-346`).
5. The modal renders repository root and directory paths, supports filtering, and keeps selection separate from the source input until Use directory is clicked (`ImportAdminPage.php:1713-1803`, `:2140-2204`).
6. Use directory replaces the source input with a canonical URL such as `https://github.com/owner/repo/tree/main/docs`; normal form submit then starts the import (`GitHubRepositorySourceUrl.php:164-172`; `ImportAdminPage.php:1796-1803`, `:2769-2802`).

Current GitHub import code uses php-toolkit sparse Git when a candidate has an explicit non-`HEAD`, non-40-character ref (`GitHubRepositorySourceWalker.php:128-223`, `:249-257`, `:365-417`). If sparse Git cannot queue files, the current code records `github.traversal_failed` and explicitly says GitHub imports do not use tree/blob, Contents API, or zipball fallbacks (`GitHubRepositorySourceWalker.php:91-117`). Some README/usage/architecture docs still describe tree/blob or zipball fallback; those docs are stale relative to current code.

Before the first GitHub worker response, the dashboard can show status "Starting", action "Queued to fetch GitHub repository files.", and note "File count appears after GitHub repository discovery." (`ImportAdminPage.php:3829-3851`, `:4120-4144`, `:4223-4225`, `:4532-4539`). While sparse Git is actively pulling, the status becomes "Fetching" and the action becomes "Fetching repository files with sparse Git." (`ImportAdminPage.php:4104-4106`, `:4124-4126`, `:4197-4199`, `:4628-4669`).

## Flow: Dry Run

Dry run is a session-level flag set by either start path (`ImportAdminPage.php:195-226`, `:241-312`; `ImportSession.php:72-77`, `:118-132`). It still discovers sources, expands archives, prepares documents, infers URL decisions, detects media references when URL treatment is not blocked, runs prepared-document URL rewriting against importer state, records events, and updates progress (`ImportRunner.php:241-258`, `:319-412`).

Dry run does not mutate WordPress content: media attachment writes, post writes, imported-post link rewrites, postmeta, attachment metadata/parents, comments, relationship mappings, and navigation menus are all no-op summaries (`ImportRunner.php:257-270`, `:1130-1296`). The runner records `session.dry_run_write_skipped` (`ImportRunner.php:1130-1146`) and may mark the session done as soon as traversal is complete and no importer-state work or decisions remain (`ImportRunner.php:674-685`).

The dashboard labels a dry-run session "Dry run" and marks the Write pages stage "Dry run: no pages written." (`ImportAdminPage.php:2366-2375`, `:3101-3103`, `:4508-4510`). A dry run can still pause on URL treatment because URL inference runs before the dry-run write skips (`ImportRunner.php:253-258`; `ImportUrlInference.php:39-94`).

## Flow: URL Rewrite Decisions

The start form has three modes (`ImportAdminPage.php:1460-1479`):

| Mode | Current behavior |
| --- | --- |
| Ask when old URLs are found | Default. With no old-site domains entered, no URL decision is saved up front. With domains entered, a resolved `confirm-first-party-domains` decision is saved immediately. |
| Keep URLs unchanged | Saves a resolved `confirm-first-party-domains` decision with an empty `confirmed_domains` array, preventing later URL prompts. |
| Rewrite listed domains | Requires at least one domain. Saves a resolved `confirm-first-party-domains` decision with those domains. |

The implementation is `save_initial_url_rewrite_preference()` (`ImportAdminPage.php:3862-3881`) plus decision-answer parsing (`ImportAdminPage.php:5040-5052`).

During runner execution, `ImportUrlInference` scans prepared document metadata, queued candidate first-party media references, and WXR nav-menu URL metadata for absolute URL domains (`ImportUrlInference.php:103-222`). It suggests exact source hosts or subdomains when source-domain clues exist; if there are no source-domain clues, it can suggest any discovered absolute URL domain (`ImportUrlInference.php:276-311`). If unresolved domains exist, it creates/updates pending `confirm-first-party-domains`, records `url.confirmation_required`, and blocks downstream work (`ImportUrlInference.php:39-94`).

When blocked:

1. The dashboard current action becomes "Choose URL treatment to continue." and attention says to answer the prompt (`ImportAdminPage.php:4183-4190`, `:4268-4270`).
2. The stage checklist blocks at URL treatment (`ImportAdminPage.php:4484-4488`).
3. The decision UI shows domains, one example URL per domain when available, and three actions: Rewrite selected domains, Yes rewrite all, No keep all URLs (`ImportAdminPage.php:2596-2636`, `:3506-3565`).
4. Resolving stores a structured answer, records `decision.resolved`, schedules continuation, and restarts keepalive in the browser (`ImportAdminPage.php:480-520`, `:2878-2882`, `:2993-3011`).

Confirmed domains are exact hosts. The rewriter changes matching absolute HTTP(S) URLs to the local site URL while preserving path, query, and fragment. Unconfirmed/outside hosts stay unchanged (`ImportUrlRewriter.php:55-156`, `:181-249`).

## Flow: Progress Keepalive

The progress card is a worker driver, not just polling.

On render, PHP chooses one primary recent session for the JavaScript config (`ImportAdminPage.php:646-681`, `:3039-3047`). The browser calls `reattachActiveSession()`. If the session needs work and has no pending decision or terminal/failed status, it starts a 5-second interval and calls `tick()` immediately (`ImportAdminPage.php:2676-2745`).

Each tick posts `session_id` to `universal_importer_keepalive` (`ImportAdminPage.php:2747-2767`, `:2955-2967`). The server creates an `ImportRunner` with owner `admin`, runs one tick, reloads the snapshot, and may burst up to four total bounded ticks when the session still needs keepalive, is not locked, has no errors, and source/media/post work remains (`ImportAdminPage.php:366-414`, `:3985-3993`).

The returned snapshot drives status label, progress percent/indeterminate mode, progress note, current action, attention message, stage checklist, activity log, pending decisions, relationship warnings, technical details, abort visibility, and final review link (`ImportAdminPage.php:530-595`, `:4058-4332`, `:2366-2416`, `:3090-3157`).

Keepalive stops when the browser sees `done`, `aborted`, `failed`, a pending decision, or `dashboard.needs_keepalive === false` (`ImportAdminPage.php:2721-2729`, `:2752-2760`). The PHP dashboard also stops keepalive for non-running statuses, attention messages, pending decisions, no queued source/media work, and no remaining page-write gap (`ImportAdminPage.php:4297-4332`).

## Flow: Abort

The Abort button is rendered whenever a session is not `done` and not `aborted` (`ImportAdminPage.php:2413-2415`, `:3155-3157`). Clicking it posts `session_id` to `universal_importer_abort_session` (`ImportAdminPage.php:2888-2893`, `:2974-2985`).

The server refuses only a completed session. Otherwise it marks the session `aborted`, records warning event `session.aborted`, and returns a fresh snapshot (`ImportAdminPage.php:439-468`). Abort is terminal for the session but not a rollback. The abort handler does not delete already persisted posts, attachments, importer rows, or staged cache files.

## Flow: Warning And Decision Resolution

There are two visible decision families today:

- URL treatment, rendered in the URL treatment stage with domain checkboxes and URL-specific buttons (`ImportAdminPage.php:2596-2636`, `:3254-3275`, `:3506-3565`).
- Generic import decisions, rendered as a prompt plus editable JSON textarea seeded from `answer_template` (`ImportAdminPage.php:2607-2610`, `:3566-3569`, `:4028-4040`).

The main generic warning/decision path is REST relationship mapping after a post has already been written. `ImportPostPersister` writes or updates the page, checks gateway relationship diagnostics, saves a `map-rest-relationships:<hash>` decision when author/term mappings are incomplete, and records `post.relationships_partially_mapped` (`ImportPostPersister.php:219-235`, `:344-360`; `ImportRelationshipMappingDecision.php:13-24`, `:59-88`). The admin card shows a Relationship warnings box and a generic decision textarea (`ImportAdminPage.php:4988-5017`, `:3481-3495`, `:3506-3569`).

Resolution posts through `universal_importer_resolve_decision`. For URL decisions, the parser builds `confirmed_domains`; for other decisions, it requires a valid JSON object (`ImportAdminPage.php:5040-5068`). The server resolves the decision, records `decision.resolved`, schedules continuation, and returns a snapshot (`ImportAdminPage.php:480-520`). Later runner ticks apply resolved relationship mappings and record applied, incomplete, or failed events (`ImportRunner.php:296-298`; `ImportRelationshipMappingApplier.php:50-74`, `:107-155`, `:170-183`).

Source and media failures are shown as attention states, but the admin UI does not offer an in-place repair or retry flow. Source failures tell the user a new import is needed; media failures say drafts may exist but media references need review (`ImportAdminPage.php:4207-4219`, `:4264-4288`, `:4436-4440`, `:4493-4497`).

## Flow: Final Import

For non-dry-run sessions, the runner reaches `done` only after source traversal is complete, no pending decisions remain, no failed source items remain, prepared document idempotency is complete, every prepared document has a persisted post, EPUB/Markdown internal links are resolved, WXR nav menus and attachment metadata/parents have no pending work, queued/failed media references are gone, comments are complete, and relationship mapping work is settled (`ImportRunner.php:674-720`, `:728-745`, `:762-921`, `:938-985`).

When the status first becomes `done`, the runner records `session.done` (`ImportRunner.php:414-426`). The dashboard shows 100%, "Import complete.", and Finish becomes complete (`ImportAdminPage.php:4071-4073`, `:4170-4173`, `:4520-4521`).

Imported documents are persisted as WordPress `page` posts through the post gateway. The initial post status is stored as a resolved `import-post-status` decision from the start form; the current admin default is `publish`, and checking Import as drafts stores `draft` (`WordPressPostGateway.php:98-150`; `ImportAdminPage.php:604-613`, `:1481-1484`, `:3891-3899`; `ImportPostPersister.php:219-235`, `:324-334`).

## Flow: Post-Import Content Review

When a completed session has at least one persisted post, the admin card renders View imported content (`ImportAdminPage.php:2410-2412`, `:3152-3154`). The link points to the Pages list filtered by session id:

```text
wp-admin/edit.php?post_type=page&universal_importer_session_id=<session-id>
```

The URL is generated by `imported_content_url()` (`ImportAdminPage.php:623-637`). The admin page also hooks `pre_get_posts` so the Pages list filters `post_type=page` by `_universal_importer_session_id = <session-id>` (`ImportAdminPage.php:113-121`, `:145-181`).

There is no equivalent review link for dry runs, completed sessions with zero persisted posts, incomplete sessions, or sessions whose only useful output is diagnostics.

## End State Files Created

- `docs/importer/progress-flow-explorations/existing-flow-map.md`
- `docs/importer/progress-flow-explorations/existing-flow-map.html`

## Key Confusing Points

- Source shortcuts mostly change placeholders; Browser folder is the only shortcut that immediately opens a picker.
- Browser files override URL/server path import. Once files are selected, the source input is not required, the GitHub picker hides, and the upload request omits `source`.
- Keepalive runs importer work and may burst multiple runner ticks in one AJAX request; it is not passive polling.
- Current GitHub import code only uses sparse Git and records failure if that cannot queue files. Several docs still describe tree/blob or zipball fallback.
- GitHub root URLs parse as `HEAD`; browsing resolves default branch, but direct import without using the picker can still enter the current sparse-Git limitation.
- "Ask when old URLs are found" plus manually entered domains resolves the URL decision immediately.
- "Keep URLs unchanged" stores an explicit empty-domain resolved decision, preventing later URL prompts.
- Dry run can still pause for URL treatment and can rewrite prepared importer-state markup, even though it skips WordPress content writes.
- The admin default is publish unless Import as drafts is checked, while README/public docs often describe normal imports as draft/review flows.
- Generic relationship decisions require hand-edited JSON in a textarea after the imported page already exists.
- Resolving any decision shows "URL choice saved." in JavaScript, even for non-URL decisions (`ImportAdminPage.php:2878-2882`).
- Abort stops future session work but does not roll back content or clean cache files.
- View imported content is page-list-only and appears only after completed sessions with persisted post records.
