# Import Flow Research

Research date: May 21, 2026.

Scope: admin importers, repository and file ingestion, migration tools, cloud transfer jobs, and setup wizards. The goal is a practical progress model for a WordPress-admin-adjacent importer: clear enough for first-time users, compact enough for repeated admin work, and resilient when imports take minutes or need intervention.

## Read This First

- The strongest flows separate setup from execution. Setup asks only for the source, permissions, destination, and safety choices needed to start.
- Good progress states answer three questions immediately: what is happening, how far along it is, and whether the user needs to act.
- Long-running imports need a durable status page, not a blocking screen. Email or dashboard updates are useful when the user can leave and return.
- The most useful error states are not just red. They preserve partial results, name the failed step, show what was skipped, and offer a next action.
- Logs are secondary. Summaries, counts, and blocking decisions belong in the main path; full technical detail belongs behind a link or disclosure.

## Source-Linked Findings

### Import and Ingestion Flows

- [GitHub Importer](https://docs.github.com/en/migrations/importing-source-code/using-github-importer/importing-a-repository-with-github-importer) keeps setup short: source URL, credentials only if needed, destination owner/name, visibility, then "Begin import." After starting, it redirects to a status page and sends email on completion. Reusable pattern: one clear start action plus an asynchronous return path.
- [GitHub Source Imports API](https://docs.github.com/en/rest/migrations/source-imports) exposes a useful state model: `detecting`, `importing`, `mapping`, `pushing`, `complete`, plus actionable problem states such as `auth_failed`, `detection_needs_auth`, `detection_found_nothing`, and `detection_found_multiple`. Reusable pattern: use named stages, show percent only where it is meaningful, and turn ambiguous source detection into a decision state.
- [GitLab GitHub import](https://docs.gitlab.com/user/project/import/github/) supports repository filtering, per-repository import actions, a live status column, cancellation, re-import, and final states of complete, partially completed, or failed. It also shows details for entities that failed. Reusable pattern: for batch imports, status belongs beside each item, not only in a global progress bar.
- [Vercel Git project import](https://vercel.com/docs/git) starts from a repository list, then lets users configure only the project name, framework preset, root directory, build output settings, and environment variables before deploy. [Vercel build logs](https://vercel.com/docs/deployments/logs) and [build troubleshooting](https://vercel.com/docs/deployments/troubleshoot-a-build) put errors, warnings, timestamps, and shareable log lines behind the deployment status. Reusable pattern: concise setup first; diagnostic depth after the build/import exists.
- [Netlify repository deploy](https://docs.netlify.com/start/quickstarts/deploy-from-repository/) is a low-friction sequence: choose import, choose Git provider, authorize, confirm publish settings, publish, then optional domain setup. Reusable pattern: defer adjacent setup tasks until after the main import/deploy succeeds.
- [WordPress.com content import](https://wordpress.com/support/import/import-a-sites-content/) makes limitations explicit: content is copied, not plugins/themes/design; existing destination content is not deleted or overwritten; media may continue in the background for hours; users should verify media and review pages/posts after import. Reusable pattern: say what will and will not happen before the user starts, then give a post-import verification checklist.
- [Shopify CSV product import](https://help.shopify.com/en/manual/products/import-export/import-products) includes a pre-import review, optional publish and overwrite choices, a confirmation email, and strong cautions about irreversible or risky changes. [Common CSV import issues](https://help.shopify.com/en/manual/products/import-export/common-import-issues) provide concrete row/header/error fixes. Reusable pattern: preview destructive choices before import; make error recovery specific enough to fix the source file.
- [Google Cloud Storage Transfer Service](https://docs.cloud.google.com/storage-transfer/docs/manage-transfers) treats transfers as jobs with run history, status, start/stop times, duration, progress, bytes transferred, skipped data, errors, and speed estimate. It supports pause/resume and separate error detail views with filtering. Reusable pattern: long imports need job-level controls, run history, and counts that match admin troubleshooting.
- [Atlassian Jira Cloud Migration Assistant](https://support.atlassian.com/migration/docs/manage-and-view-the-details-of-jira-migration-plans/) uses a migration dashboard with saved/running/finished/incomplete/failed states, pre-migration checks, post-migration reports, error logs, app-specific migration statuses, re-run for failed app migrations, and cancel for stuck app migrations. Reusable pattern: migrations should surface readiness checks before execution and distinguish "finished with gaps" from "failed."

### Progress and Wizard Guidance

- [NN/g response time limits](https://www.nngroup.com/articles/response-times-3-important-limits/) remain useful for importer UI: under 1 second can feel direct; beyond 1 second should show working feedback; beyond 10 seconds needs progress and an interruption or return path.
- [NN/g progress indicators](https://www.nngroup.com/articles/progress-indicators/) recommends immediate feedback, looped indicators only for short waits, percent-done or step-based progress for longer waits, and avoiding static "please wait" states. Reusable pattern: spinners are a short bridge, not a long-running import UI.
- [NN/g status trackers and progress updates](https://www.nngroup.com/articles/status-tracker-progress-update/) distinguishes pull status trackers from push updates. It recommends latest status first, plain-language updates, previous updates with dates, regular low-granularity updates for long gaps, and direct links from notifications back to the tracker. Reusable pattern: the status page and notification emails should agree and link to each other.
- [Apple progress indicators](https://developer.apple.com/design/human-interface-guidelines/progress-indicators/) emphasizes determinate progress when possible, accurate pacing, consistent placement, helpful descriptions, and cancel/pause only when consequences are clear. Reusable pattern: never make the bar race to 90 percent and stall; use stage text when the denominator is unknowable.
- [Material Web progress indicators](https://material-web.dev/components/progress/) supports circular and linear progress, including buffer progress where part of the work is known and part is still unknown. Reusable pattern: an importer can show known item progress plus "still discovering media/files" without pretending the whole job is known.
- [Microsoft wizard guidance](https://learn.microsoft.com/en-us/windows/win32/uxguide/win-wizards) argues for "one wizard, one task," page integrity, minimal branching, concise progress text, a single determinate progress bar, and no technical clutter on progress pages. Reusable pattern: do not turn the progress screen into support output.
- [GOV.UK task list](https://design-system.service.gov.uk/components/task-list/) is useful when users cannot complete a long service in one sitting or can choose task order. It also warns to simplify before adding a task list. Reusable pattern: use a task list for optional preparation and review tasks, not for a linear import that should just proceed.

## Reusable Principles

### Clarity

- Name the current state in plain language: "Scanning repository," "Importing media," "Needs URL decision," "Finished with 8 skipped items."
- Put the latest and most important status first. Older activity and full logs can sit below or behind disclosure.
- Show what the import will affect before writing: creates, updates, skips, deletions if any, URL rewrites, author mapping, publication status.
- Do not say "complete" if background work continues. Say "content imported; media still downloading" when that is the real state.

### No Bloat

- Keep the start screen to source, destination/scope, safety mode, and the primary action.
- Defer advanced mapping, URL handling, overwrite behavior, and logs until the scan produces evidence that those choices matter.
- Keep optional post-import setup out of the import path. Domain setup, design work, user onboarding, and cleanup tasks can be next steps after the result.
- Hide technical noise by default. Counts and decisions are primary; stack traces, GUIDs, raw logs, and row dumps are secondary.

### Discoverability

- Make the status page findable from the importer landing page, recent activity, completion email, and any error notification.
- Persist previous runs with date, source, result, and "view details" so admins can answer "what happened last time?"
- Surface blocking decisions at the top of the run details page. Do not bury "needs authentication" or "choose one repository" below stage history.
- Use labels that match admin intent: "Dry run," "Run import," "Review skipped items," "Download report," "Resume," "Re-run failed items."

### Few Clicks

- Support paste-first starts for URLs and drag/add-file starts for files.
- Ask for credentials only after detecting they are needed.
- Use smart defaults: dry run first, save as drafts when risk is high, do not overwrite without explicit review.
- Batch where safe. For multiple repositories/files, let users select many and start once, then manage per-item statuses in place.

### Long Wait Handling

- For waits over 10 seconds, show a durable progress view with stage, count, percent when valid, elapsed time, and last update time.
- When total work is unknown, show work completed and discovery status instead of fake percentages.
- Allow users to leave and return. Send completion or blocked-state notifications when feasible.
- Provide pause/resume only when it is technically real and safe. Otherwise provide cancel with clear consequences.
- Keep low-granularity updates flowing during long quiet periods: queued, scanning, importing posts, importing media, rewriting links, verifying, generating report.

### When There Is No Clear Path Forward

- Split problem states: "needs user action," "partially completed," "failed before writing," "failed after partial write," and "completed with warnings."
- Each problem state needs a primary next action: authenticate, choose a source, upload a smaller file, download error report, retry failed items, re-run dry run, or contact support with a report.
- Preserve evidence. Show what succeeded, what was skipped, what remains unchanged, and whether retrying will duplicate or overwrite anything.
- If the importer cannot continue automatically, say so directly and offer the closest manual path.

## Recommendations For Designers

1. Design the importer as four durable states: setup, scan/dry run, decision/review, and result. Avoid showing all states on the first screen.
2. Put one primary action in each state. Secondary actions should be visible but visually quieter: cancel, download report, view logs, back to runs.
3. Build the progress surface around human questions: "Is it working?", "Can I leave?", "Do I need to do anything?", "What changed?"
4. Use progress bars only when the denominator is defensible. Otherwise use stage text, item counts, and recent activity.
5. Treat partial completion as a first-class result, not an error footnote. Designers should specify the summary, report, retry path, and risk copy.
6. Keep the happy path short, then make recovery strong. Most complexity belongs after detection, not before start.
7. Prototype long waits and blocked states, not just the start form. The importer will be judged by what happens after the admin clicks "Start dry run."
