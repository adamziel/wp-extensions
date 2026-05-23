All checks pass. Summary:

## Changes
- **Dropped the standalone "Stage X of N · stage name" line** from `render_session_list()` (PHP) and `renderSession()` (JS). The progress bar carries the percentage; the active stage row already names the stage. Errors-only summary line stays.
- **Reordered card body** so the meta sequence is now `[status meta] → [progress bar] → [current-action] → [Import stages]`.
- **Filtered "This stage so far" panel** with a new `stage_log_row_duplicates_current_action()` (PHP) / `stageLogRowDuplicatesCurrentAction()` (JS) using a normalized token signature so rows that semantically restate the current-action line are dropped; whole panel hides when nothing remains. Added `github.fetch_queued` to both the status-placeholder filter and the `source.fetching` semantic group.
- **Hoisted the URL-treatment decision** out of the Import stages list:
  - URL-treatment row is dropped from the checklist when a `confirm-first-party-domains` decision is pending.
  - New `render_hoisted_url_decision()` PHP method + `renderHoistedUrlDecision()` JS render the decision card as its own focal block (warm-amber accent surround) under the Import stages list.
  - Old `render_stage_decision()` reduced to a no-op.
- **Dropped "Needs attention / Answer the prompt below…" block** when a decision is pending. Drops the current-action line ("Choose URL treatment to continue.") too, because the decision card heading speaks the same fact.
- **Pluralization audit** — wrapped every `%d X item(s)…` interpolation in `dashboard_checklist()`, `dashboard_current_action()`, `dashboard_attention_message()` with `1 === $n ? singular : plural`.
- **Percentage math bug** retired by removing the per-stage progress line entirely.
- **Tests:** updated one brittle assertion that expected the old `renderStageDecision(session, stageKey)` signature; added asserts for `renderHoistedUrlDecision`.
- **Snapshot tool** updated so `--scenario=stage-1-early` includes the real-world `github.fetch_queued` event and `--scenario=stage-3-decision` includes the live dashboard's `attention_message` and `current_action`, exercising the new filters.

## Final grep audit (all counts ≤ 1)
```
--- /tmp/early-body.html ---
      1 URL treatment
      1 Read source
      1 Queued to fetch GitHub
      1 Prepare content
--- /tmp/mid-body.html ---
      1 URL treatment
      1 Read source
      1 Prepare content
--- /tmp/decision-body.html ---
      1 URL treatment
      1 Read source
      1 Prepare content
      1 Needs attention
```
- "Stage X of 6": 0 matches across all three scenarios.
- "GitHub repository fetch queued": 0 in early-body (status-placeholder filter).
- "Answer the prompt below to continue": 0 in decision-body.
- "Choose URL treatment to continue.": 0 in decision-body.
- "Preparing 55 item" (singular w/ plural N): 0 in all bodies; `Preparing 112 items.` renders correctly in stage-2-mid.
- "X of Y items (N%)" math string: 0 (line dropped per spec, so the math bug can no longer manifest).
- `composer test`: 495 tests, 5804 assertions, all pass.
- `verify-option-30-flow.js`: Verdict pass.
- Screenshots saved to `.tmp/v6-shots/loop5-1/{early,mid,decision,stage2}.png`.

VERDICT: PASS
