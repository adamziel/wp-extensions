My reproduction of the resolved-state screenshot matches the implementer's `running-resolved-1280.png` byte-for-byte (79898 bytes). Now compiling the verdict.

### Per-criterion checklist

- [✓] **Stage focus / done-stages roll up to one-liner** — `render_dashboard_checklist` at `ImportAdminPage.php:5337-5440` keeps done rows visible with `is-done-row` class, ✓ glyph, and the stage's `detail` ("117 source items found." / "117 documents ready." / "URL choice is set."). Pending non-next rows are `hidden`. Evidence: `.tmp/v6-shots/loop3-1/running-stage2-1280.png` (Read source rolled up) and `running-resolved-1280.png` (3 rolled up).
- [✓] **Per-stage activity log scoped to current stage** — `group_events_by_stage` (PHP `:5571`) and `groupEventsByStage` (JS `:4017`) bucket events by stage key using `stage_for_event_type` (PHP `:5471`) / `stageForEvent` (JS `:3994`). Activity log is only rendered when `$is_active_row && isset( $item['key'] )` (`:5426`). Evidence: snapshot.html line 2045-2050 shows the active "Import media" row's stage-log contains only media + url events, not the prior stages' events.
- [✓] **Dedup of repeats** — `dedup_events` (PHP `:5593`) and `dedupEvents` (JS `:4089`) group by `t:<type>` for boilerplate types. 5 identical `document.prepared` events render as one row. Evidence: snapshot.html `5 / 117 documents converted to block markup`, `running-stage2-1280.png`.
- [✓] **Dedup uses progress.total** — line `:5630-5632` and JS `:4117-4119` apply the `count / total` format only for document types when `total > count`. Evidence: snapshot.html "5 / 117 documents converted to block markup".
- [✓] **URL decision per-host selectable** — `renderUrlDecision` (JS `:4370`) and SSR render emit a checkbox per domain with example URL underneath. Primary button has `<span data-selected-count>(N)</span>` and `updateDecisionPrimaryCount` (`:4651`) updates the count live on the `change` event (`:4701-4706`). Evidence: SSR markup shows `(3)` matches 3 default-checked hosts; live wiring via `sessions.addEventListener('change', ...)`.
- [✓] **Secondary actions present** — `Keep all URLs as-is` (`url_choice="none"`) + `Don't ask again — keep all` (`url_choice="never"`) at `:4401-4402` and `:6028-6029`. Evidence: `running-decision-1280.png`.
- [✓] **"Don't ask again" wires through** — Click handler at `:4749` maps `never` → `persistent=true`, stores `{persistent: true, mode: 'preserve'}` in sessionStorage via `writeUrlPolicy` (`:4207`). `autoResolvePersistentUrlPolicy` (`:4681`) fires on every `syncPrimaryView` (`:4461`) and silently resolves URL prompts with `confirmed_domains=''` through the unchanged `AJAX_DECIDE` surface.
- [✓] **Visual chips persist after decision** — `render_url_policy_card` (PHP `:5678`) and `renderConfirmedDomainsCard` (JS `:4218`) emit chips with ✓ glyph. Evidence: snapshot.html line 2082-2088, `running-resolved-1280.png`.
- [✓] **Spacing rhythm preserved** — Counts: 7 distinct `font-size:` / 13 `padding:` / 12 `margin:` values, **identical to HEAD baseline** (verified via `git stash`/`stash pop`).
- [✓] **Composer tests pass** — I ran it: `OK (492 tests, 5794 assertions)`.
- [✓] **Verifier passes** — I ran `node tools/verify-option-30-flow.js`: `Verdict: pass` (after restoring the default snapshot — the implementer's prior snapshot had been overwritten with the running-state preview).
- [✓] **Backend surface unchanged** — `git diff --stat HEAD` lists only `src/Admin/ImportAdminPage.php`, `tests/Unit/Admin/ImportAdminPageTest.php`, `tools/render-admin-snapshot.php`. No `src/Import/*` changes; no AJAX request/response shape changes — only one new client-side call to `AJAX_DECIDE` (auto-resolve), all existing keys.

### Findings

**LOW · Snapshot left in non-default state after implementer's run**

- Evidence: After implementer ran `--scenario=stage-3-resolved` last, `snapshot.html` was left in resolved mode. `node tools/verify-option-30-flow.js` then fails with `source input missing` because the running-state preview doesn't contain the source form. Implementer's claim that the verifier passes is true, but only after regenerating the default snapshot first.
- Reproduction: `php tools/render-admin-snapshot.php --scenario=stage-3-resolved && node tools/verify-option-30-flow.js /run/current-system/sw/bin/chromium` → fail. `php tools/render-admin-snapshot.php && node tools/verify-option-30-flow.js …` → pass.
- Suggested action: None blocking — the verifier itself is correct; this is a workflow artifact. Mention in the iteration ritual that the verifier needs the default snapshot.

**LOW · `(3)` badge in the primary button has no styling**

- Evidence: `<span data-selected-count>` (line 4400) has no CSS rules; it renders at base button weight/size. In the screenshot the count reads as small text adjacent to "Rewrite selected", which is functional but visually thin.
- Reproduction: Inspect SSR snapshot HTML.
- Suggested action: Optional polish — could wrap count in a quiet inverse pill. Not a blocker.

### Verdict

All twelve acceptance criteria pass with concrete evidence. The screenshots are visibly cleaner than the baseline: previous stages collapse to one-liner roll-ups with ✓, the active stage owns its own dedup'd log, the URL decision shows per-host checkboxes with live count and three actions, and a persistent chips card stays visible across stages. Dedup is real and tested (3 new PHPUnit cases). Per-host selection is real and live-updated. Backend AJAX surface is untouched. No forbidden moves reappeared.

VERDICT: PASS
