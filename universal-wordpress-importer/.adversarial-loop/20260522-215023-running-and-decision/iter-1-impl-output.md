All tasks completed. Iteration summary:

## VERDICT: PASS

**What changed (single pass, primary file: `src/Admin/ImportAdminPage.php`):**

- **Stage-focused running view** — done stages now render as one-line summaries (e.g. `Read source DONE 117 source items found.`) instead of being hidden. The per-stage activity log lives directly inside the active stage card, scoped to events that belong to that stage via `stageForEvent`/`stage_for_event_type` mapping.
- **Event dedup** — both client (`dedupEvents`) and server (`dedup_events`) collapse repeated boilerplate types. Five identical `document.prepared` events render as a single `5 / 117 documents converted to block markup` row using `progress.total`. Non-document templates (media, URLs) omit the total since "117 documents" isn't a meaningful ceiling for them. Distinct paths on non-boilerplate types stay as separate rows; exact-message repeats get a `2 × …` prefix.
- **URL decision rework** — per-host rows with `[x] host · N URLs found / example URL`, primary `Rewrite selected (N)` with a live-updating count that disables at 0, plus two flavors of no: `Keep all URLs as-is` and `Don’t ask again — keep all`. "Don't ask again" writes a persistent policy to `sessionStorage` and `autoResolvePersistentUrlPolicy` silently resolves any future URL-treatment prompt in the same session through the unchanged AJAX surface.
- **Persistent chips card** — after the decision resolves, a quiet "Rewriting URLs from:" card with check-glyph chips stays visible for the rest of the run (above the relationship-warnings card so it's a session-wide note, not stage-local).
- **Spacing rhythm** — 7 / 13 / 12 distinct `font-size:` / `padding:` / `margin:` shorthand tokens, matching the baseline exactly. No new fonts, no external resources.

**Evidence:**
- `.tmp/v6-shots/loop3-1/running-stage2-1280.png` — stage 2 mid-flight with `Read source DONE 117 source items found.` rolled-up summary above the active "Prepare content" panel; `5 / 117 documents converted to block markup` dedup row.
- `.tmp/v6-shots/loop3-1/running-decision-1280.png` — per-host UI with 3 boxes pre-checked, `Rewrite selected (3)` primary, `Keep all URLs as-is` + `Don't ask again — keep all`.
- `.tmp/v6-shots/loop3-1/running-resolved-1280.png` — three done one-liners, mid Import-media with `3 media items imported` / `3 URLs rewritten`, persistent chips `cli.github.com ✓ docs.github.com ✓ github.com ✓`.
- `composer test`: **492 tests, 5794 assertions, OK** (added 3 dedup tests).
- `node tools/verify-option-30-flow.js`: **Verdict: pass**.
- `git diff --stat`: only `src/Admin/ImportAdminPage.php`, `tests/Unit/Admin/ImportAdminPageTest.php`, `tools/render-admin-snapshot.php` modified — AJAX contract untouched.
