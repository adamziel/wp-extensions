# Universal WordPress Importer Flow Critique

Critique date: May 21, 2026.

Scope: existing admin user flows only. This is based on `existing-flow-map.md`, the current Universal WordPress Importer admin code, the importer README/usage docs, and the public importer docs pages. Plugin runtime code was not edited.

## Source Basis

- `docs/importer/progress-flow-explorations/existing-flow-map.md`
- `universal-wordpress-importer/src/Admin/ImportAdminPage.php`
- `universal-wordpress-importer/README.md`
- `universal-wordpress-importer/docs/usage.md`
- `universal-wordpress-importer/docs/recovery-model.md`
- `docs/importer/index.html`
- `docs/importer/get-started.html`
- `docs/importer/formats.html`
- `docs/importer/examples.html`

## Overall Critique

The underlying importer model is strong: sessions are durable, keepalive and WP-Cron share the same runner, decisions are persisted, and the admin screen has a real stage checklist instead of a fake spinner. The user flow is weaker at the boundaries: the start screen asks for several policy choices before users have evidence, the progress card hides useful detail behind a technical disclosure, and blocked states often say "needs attention" without making the next safe action obvious.

The current flow also mixes two product promises. The docs and PortPress pages repeatedly frame imports as "reviewable WordPress drafts," while the current admin default publishes pages unless "Import as drafts" is checked. That mismatch is the highest-risk ambiguity because it affects real content visibility.

## Confusing Or Ambiguous Moments

- Source shortcut buttons look like source-type choices, but most only change the placeholder and focus the same text input. The exception is Browser folder, which opens a folder picker. That split behavior is discoverable only after clicking.
- Browser uploads silently override the typed URL/server path flow. Once files are selected, the source field is no longer required, the GitHub picker is hidden, and the submitted upload path ignores the typed source value. This is technically coherent, but it can surprise users who paste a source and then add a supporting file.
- The start form asks for URL treatment before the importer has shown any detected domains. "Ask when old URLs are found" plus manually entered domains resolves the URL decision immediately; "Keep URLs unchanged" stores an empty-domain decision that prevents later prompts. These are defensible behaviors, but the labels do not explain the lasting consequence.
- Dry run sounds non-committal, but it can still stop on URL treatment. The current docs say dry run traverses and prepares without writing posts, while the flow map confirms a dry run can still require a URL decision before finishing.
- Publishing behavior is ambiguous across code and docs. The admin form says "Import as drafts" is optional and unchecked by default, while `README.md`, `docs/importer/index.html`, and `docs/importer/get-started.html` describe normal imports as draft/review flows.
- The progress card labels the mode as "Publishes pages," "Creates drafts," or "Dry run," but that label appears only after the import has started. The riskiest setting is not summarized near the primary submit button.
- Relationship decisions fall back to a generic JSON textarea. A non-developer admin sees a decision key, a prompt, editable JSON, and a "Resolve decision" button, but not a guided path for mapping a remote author or term.
- Resolving any decision shows the success notice "URL choice saved." That is accurate for URL treatment, but misleading for relationship mapping or future non-URL decisions.
- Abort is visible during active imports, but the UI does not state beside the button that abort is terminal and not a rollback. The flow map confirms existing posts, attachments, staged files, and rows remain.

## Long Waits

- Keepalive does real importer work, not just polling. The browser calls the endpoint immediately and every 5 seconds, and each request may run multiple bounded ticks. Users are watching a worker, but the UI reads like a status refresh.
- During GitHub discovery and sparse Git fetches, the progress bar can be indeterminate and the count can stay unknown. The note "File count appears after GitHub repository discovery" is honest, but it does not give elapsed time, last update time, next retry, or a reason to trust that work is still advancing.
- Remote backoff is visible in technical details, but the primary current action only says "Waiting for the remote source." The user has to open the disclosure to find retry timing and the affected URL.
- Browser upload start has no upload-progress state. Large uploads move from selected files to "Import started" only after the AJAX request returns, so the first long wait can happen before the durable session exists.
- Final checks can feel opaque. A session reaches `done` only after source traversal, decisions, media, posts, links, WXR menus, metadata, comments, attachment parents, and relationship mappings are settled. The progress surface compresses all of that into "Final checks."

## Unclear Path Forward

- Failed source items say the importer will not continue until the source problem is corrected and a new import is started. The admin screen does not offer a "retry/resume" path even though the docs describe CLI `resume` for some recoverable cases.
- Failed media says drafts may exist and media references need review. It does not link to the affected pages, list the failed media prominently outside technical details, or say whether the rest of the import can be trusted.
- A dry run completion has no obvious "run this import for real with the same source and settings" action. Users must scroll back to the start form and re-enter or preserve the same choices themselves.
- If no importable documents are found, the checklist can say "No importable documents found," but the next path is not framed as "change source," "choose another folder," or "review supported formats."
- Relationship warnings remain a post-write corrective flow. The imported page already exists, but the session is not complete; the UI does not make that partial state plain enough.
- "View imported content" appears only after completed sessions with persisted posts. There is no equivalent result affordance for dry runs, no-content sessions, or completed sessions whose useful output is warnings and diagnostics.

## Too Many Clicks

- GitHub subtree import is paste URL, wait for the hidden picker to appear, click Choose directory, wait for the modal, select a directory, click Use directory, then click Import. That is a lot of ceremony for a common "import this repo folder" job.
- URL decision handling duplicates choices: selected checkboxes plus "Rewrite selected domains," "Yes, rewrite all," and "No, keep all URLs." The three-button row is flexible, but the primary choice is visually busy at exactly the moment the import is blocked.
- Browser folder import has both a source shortcut and separate upload buttons in the dropzone. The same action is present in two places, and only one of the five shortcut buttons actually performs an immediate source-picking action.
- Post-import review requires waiting for done, then clicking "View imported content" to leave the run. That is reasonable, but the result card does not first summarize what changed, so the click carries too much evaluative work.
- Starting another import from an attention state is possible only in a narrow path where `canStartAnotherImport()` returns true. Otherwise the form can stay hidden while the user is focused on a blocked session.

## Hidden Discoverability

- The GitHub directory picker appears only after the input looks like a GitHub repository URL and no browser files are selected. Users who click the GitHub shortcut get only a placeholder change, not an obvious browse path.
- The admin page renders one primary recent session into the JavaScript config. There is no visible run history with source, date, status, and "view details," so returning admins may not know where a previous import went.
- The selected-file tree has useful keyboard behaviors, but the UI does not surface why only 120 files are previewed or how to interpret a large folder beyond the summary count.
- Technical details contain important troubleshooting context: failed source item errors, remote backoff, PDF/OCR notes, EPUB TOCs, and media references. Because it is labeled "Technical details," non-technical admins may avoid the only place that explains what happened.
- CLI recovery commands are documented, but the admin UI does not reveal when a blocked browser session is recoverable through CLI versus when a new import is actually required.

## Progress Opacity

- The percent is based on completed versus total progress/source items when known, but the completion definition spans more work than source item progress: media import, post writes, internal links, comments, menus, metadata, and relationship mapping can still remain.
- The main summary reads like "N / total items complete." It does not distinguish source items scanned, documents prepared, pages written, media imported, comments imported, and skipped items until the disclosure is opened.
- Stage labels are clear, but stage details are sometimes too generic: "Queued," "Looking for importable content," "Final checks," and "Checking import state" are not enough for waits longer than a few seconds.
- The activity log is called "Done so far," but it shows recent event messages without timestamps or an explicit "latest update" marker.
- Relationship warnings are shown as a warning box plus generic decisions, but they are not represented as a first-class stage. Users can see "Write pages" as done while a post-write mapping decision still blocks completion.

## Bloat Risks

- The start screen is already doing source selection, upload preview, URL rewrite policy, old-domain entry, publish/draft choice, dry-run mode, GitHub browse, and session start. Adding more controls there would make the first step heavier.
- The technical disclosure is accumulating every subsystem: source items, prepared documents, media, remote backoff, PDF/OCR, EPUB TOCs, comments, and relationships. It risks becoming a support dump rather than a user progress view.
- A full multi-step wizard could add ceremony without improving repeat imports. The current paste/drop/start affordance is valuable and should remain available.
- Adding source-specific screens for every input type would duplicate behavior that the runner already unifies. The safer direction is progressive disclosure around the same session model.
- Post-import cleanup, design review, publishing workflows, and domain setup are adjacent tasks. Pulling them into the importer would blur the product boundary and slow down the import job.

## 10 User-Flow Ideas

1. Add a compact "What will happen" summary above the submit button. It should state source mode, dry run versus write, publish versus draft, and URL treatment using the existing form values.
2. Change the primary button label based on risk: "Start dry run," "Import as drafts," or "Import and publish pages." This preserves the existing settings but makes the chosen consequence visible before submit.
3. Make source shortcuts act as selected source-mode chips. Keep the single input and upload controls, but show the selected mode and one mode-specific hint instead of only changing the placeholder.
4. Keep URL treatment on the start screen, but collapse old-site domain entry until the user chooses "Rewrite listed domains" or opens advanced options. The default "ask later" path should feel lightweight.
5. Promote GitHub browsing from hidden helper to inline helper: after the GitHub shortcut or a GitHub-looking paste, show "Browse repository folders" and "Import repository root" as the two clear next actions.
6. Add an upload-in-progress state before session creation. Show selected count, total size, and "Uploading to create import session" so large browser uploads do not look frozen.
7. On dry-run completion, show "Run import with these settings" as the primary next action, plus a visible summary of documents found, URL decisions, likely media, and skipped items from existing session data.
8. Replace generic blocked-state copy with a "Needs your action" panel that names the blocker type, why the importer paused, and the safest next action. Keep technical details below it.
9. Add a small recent-runs list below the current import: source, started time, status, mode, and "view run." This uses the durable session model without changing import capabilities.
10. Turn the completed state into a result summary before the "View imported content" button: pages written, media imported, comments imported, skipped/failed items, and warnings. For dry runs, show the same shape with "would create" counts where available.

## Files Created

- `docs/importer/progress-flow-explorations/flow-critique.md`
