All criteria met. Final summary:

## Summary

**What landed (src/Admin/ImportAdminPage.php, tools/render-admin-snapshot.php, tests/Unit/Admin/ImportAdminPageTest.php):**

- **Semantic-key dedup registry (PHP + JS twin)** — distinct types `source.queued` / `source.fetching` / `github.git_queued` / `github.git_fetching` etc. all collapse to one semantic group (`source.fetching`); latest phrasing wins.
- **Diagnostic-noise filter** — events typed `github.git_unavailable`/`github.traversal_failed`/`remote.*_unavailable` plus messages containing `Invalid Git ref`/`will try the next`/`fell back to`/`php-toolkit`/`Throwable:`/`WordPress\\` are filtered out of the user log. They still surface inside a new "Recent events" list inside Technical details with a `recovered` tag.
- **Status-placeholder filter** — pre-discovery events that just restate the current-action line (`source.queued`, `github.git_fetching`, etc.) don't render in "This stage so far".
- **Up-next visual margin** — new `.is-active-row + .is-next-row { margin-top: 12px }` rule (no new token; spacing-rhythm counts unchanged: font-size 7/7, padding 13/13, margin 12/12).
- **URL-treatment heading collision** — `render_pending_decisions`/`renderDecisions` accept an `inside_stage` flag that suppresses the `<h4>URL treatment</h4>` heading when rendered inside the active stage row.
- **Active stage detail empties when current_action already speaks** — `dashboard_checklist` returns `''` for the queued/fetching/git-active early states.
- **progress_note no longer beats progress_summary** — the structural `Stage X of N · …` line wins; the sentence-shaped note only appears when no structural summary exists.
- **Snapshot tool** — new `--scenario=stage-1-early` (GitHub-queued state with a recovered sparse-Git ref failure in the event stream); default `--running` & `stage-3-decision` cleaned up to use empty active-row details.

**Evidence files:**
- `.tmp/v6-shots/loop4-1/early.{html,png}` — current-action "Queued to fetch GitHub repository files." once, no panel, Up-next has visible margin.
- `.tmp/v6-shots/loop4-1/mid.{html,png}` — real progress events under "This stage so far".
- `.tmp/v6-shots/loop4-1/decision.{html,png}` — URL treatment appears twice total (progress summary + active row label); no h4 duplicate; visible margin to "Import media · Up next".
- `grep "Invalid Git ref" early.html` → 1 hit, inside the Technical details `<ul class="universal-importer-pipeline-events">`.

**Tests:** 495/5802 (was 492/5790). `verify-option-30-flow.js` Verdict: pass.
