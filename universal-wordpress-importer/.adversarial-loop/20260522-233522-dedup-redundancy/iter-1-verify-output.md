All criteria verified. Let me compile the verdict.

### Per-criterion checklist

[✓] **No fact appears more than twice on screen.**
Evidence: In `/tmp/early.html` body (post-stylesheet), the fact "fetch GitHub" appears in exactly ONE user-facing location: `<p class="universal-importer-current-action">Queued to fetch GitHub repository files.</p>` (line 1986). The active stage row has no detail and no "This stage so far" panel. Other mentions are inside `<details class="universal-importer-pipeline">` (Technical details), which is collapsed by default and not part of the user log.

[✓] **Dedup groups by semantic key, not exact text.**
Evidence: `src/Admin/ImportAdminPage.php:5676` `semantic_group_for_event_type()` maps 8 types (source.queued, source.fetching, source.discovery, source.discovery_progress, source.discovery_complete, github.git_queued, github.git_fetching, remote.fetching) → `source.fetching`. JS twin at line 4091 `SEMANTIC_GROUP_FOR_TYPE`. Test `test_dedup_events_collapses_semantic_source_fetching_into_one_row` (line 1486) explicitly feeds 3 semantically-equivalent events and asserts they collapse.

[✓] **Recovered-failure diagnostics hidden.**
Evidence: `grep "Invalid Git ref" /tmp/early.html` → **single hit at line 2092, inside `<details class="universal-importer-pipeline">` → `<ul class="universal-importer-pipeline-events">` (Technical details), tagged `<span class="universal-importer-pipeline-noise-tag">recovered</span>`**. NOT present in mid or decision snapshots. Filter at `src/Admin/ImportAdminPage.php:5707` `is_diagnostic_noise_event()` with both type and substring matching.

[✓] **Up-next row has visible vertical margin.**
Evidence: `src/Admin/ImportAdminPage.php:1995-1997` — `.universal-importer-step.is-active-row + .universal-importer-step.is-next-row { margin-top: 12px; }`. Visible in `loop4-1/early.png` and `loop4-1/decision.png`.

[✓] **"URL treatment" label not duplicated** in the decision card.
Evidence: `awk 'NR>=1972' /tmp/decision.html | grep -c "URL treatment"` → **2** (once in progress-line "Stage 3 of 6 · URL treatment", once in active stage row `<strong>URL treatment</strong>`). No `<h4>` headings present in the decision body. Decision card uses `<p><strong>Rewrite old-site URLs to this site?</strong></p>`.

[✓] **"This stage so far" panel hidden when no progress events.**
Evidence: `awk 'NR>=1972' /tmp/early.html | grep -i "stage so far\|step-history"` → empty. Active stage `<span class="universal-importer-step-body">` in early state has no `.universal-importer-stage-log` div. Mid scenario, in contrast, shows the panel with real "Read /2024/…" entries.

[✓] **Active stage detail is a short fragment or empty.**
Evidence: Early-state active stage row content is only `<strong>Read source</strong><span>In progress</span>` — no detail text at all. Source: `dashboard_checklist` returns `''` for queued/fetching states (implementer claim).

[✓] **Composer test passes ≥ 492 / ≥ 5794.**
Evidence: `OK (495 tests, 5802 assertions)`.

[✓] **verify-option-30-flow.js passes.**
Evidence: `Verdict: pass` after `node tools/verify-option-30-flow.js /run/current-system/sw/bin/chromium`.

[✓] **Spacing rhythm preserved.**
Evidence: HEAD baseline vs current — font-size 7/7, padding 13/13, margin 12/12. Identical.

[✓] **No forbidden moves reintroduced.**
Evidence: `grep "Server path\|Configure the run\|Edit anything above"` across all three snapshots → no matches.

### Findings

No defects found. The implementation matches each acceptance criterion with concrete, gathered evidence. Notable strengths:
- Both the recovered-failure event AND its message-substring matchers are covered (defence in depth — a custom `type` with `"fell back to"` in the message is still filtered, per `test_is_diagnostic_noise_event_matches_recovery_substrings`).
- PHP and JS dedup logic are kept in sync, with code comments on both sides referencing the twin.
- Spacing rhythm preserved without introducing new tokens.

### Verdict
VERDICT: PASS
