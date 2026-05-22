# Task
# Task — running state: one source of truth per fact

Working dir: `/home/claude/wp-extensions-work/universal-wordpress-importer/`
Primary file: `src/Admin/ImportAdminPage.php`.
Baseline: the user's verbatim quoted screenshots, reproduced below.

The user is frustrated that the same fact is stated several times on the screen. This is a SHARP cleanup pass, not a redesign. Keep the existing structure (top meta line · current action · progress bar · `IMPORT STAGES` · active stage card · `Up next` row · `Show all stages` disclosure · persistent rewriting-URLs chips · `Technical details` · Abort). Tighten everything inside it.

## User's verbatim examples

### Example A (very-early stage)
```
https://github.com/WordPress/gutenberg/tree/trunk/docs
Starting · Publishes pages · Working

Queued to fetch GitHub repository files.

File count appears after GitHub repository discovery.

Import stages
1  Read source  In progress  Waiting to fetch repository files from GitHub.
This stage so far
GitHub repository fetch queued; file count will appear after discovery.
2  Prepare content  Up next
Show all stages
Technical details
```

Distinct mentions of the same fact ("waiting to fetch from GitHub"):
- Current action line: `Queued to fetch GitHub repository files.`
- Second status line: `File count appears after GitHub repository discovery.`
- Active stage card detail: `Waiting to fetch repository files from GitHub.`
- "This stage so far" entry: `GitHub repository fetch queued; file count will appear after discovery.`

**Four restatements of one fact.**

### Example B (after a recovered candidate failure)
```
Fetching repository files with sparse Git.
Fetching repository files; file count appears after discovery.

Import stages
1  Read source  In progress  Fetching repository files with sparse Git.
This stage so far
2 × Fetching GitHub repository files through sparse Git checkout.
php-toolkit Git traversal failed for ref "trunk/docs" at path "/": Invalid Git ref: branch names cannot contain a slash. The importer will try the next GitHub path candidate.
GitHub repository fetch queued; file count will appear after discovery.
2  Prepare content  Up next
```

Distinct restatements of "fetching from GitHub": 4–5. **And** a technical error from a recovered failure is leaking into the user-facing log. That error was caught and the importer fell through to the next candidate — the user should never see it in the main view (it can stay in Technical details).

### Layout bug
```
URL treatment  Up next
URL treatment
```
The "Up next" row label `URL treatment` and the next-renderer's `URL treatment` heading appear stacked with no margin between them — duplicated label, broken rhythm.

## What to implement (every bullet must land)

### 1. One source of truth per fact

Pick exactly ONE place to surface "what's happening right now":

- **Top meta line**: source URL + status + mode + Working indicator. Keep as the headline summary.
- **Current action line** (one line, below the meta line): the imperative verb sentence. E.g. `Reading the source` or `Preparing 117 documents`. This is the only place the verb is written.
- **Progress bar + summary line**: `Stage 1 of 6 · <stage label> · <fraction> (<percent>%)`.
- **Active stage card**: shows the stage label + status pill + a TERSE detail (one short fragment, not a sentence). The detail must not repeat what the current-action line already says.
- **"This stage so far" panel**: lists deduplicated *progress* events only — NOT a re-statement of "what's happening". If there's only an in-flight action and no real progress yet, show nothing in this panel (or a quiet `No items yet`).

### 2. Aggressive dedup

The current dedup groups by event type. The user's examples show different *types* with the same *meaning* still stacking:
- `source.queued`, `source.fetching`, `source.discovery` may all map to "fetching from GitHub". Group those into ONE displayed row, with the latest phrasing winning.
- Build a small template registry: each known event-type maps to a display template + a logical group key. Events sharing a group key collapse to one row whose message comes from the latest event.
- For events without a known template (custom or one-off), show as-is.

### 3. Hide recovered-failure noise from the user log

- Events whose message contains "will try the next … candidate", "fell back to", "Invalid Git ref", or other recovery diagnostics should NOT appear in the user-facing activity log. They belong in **Technical details**. Tag events server-side or filter client-side using a small list of substrings.
- More generally: any event whose `type` is `*.warning.recovered` or whose message starts with the technical-stack signatures (`php-toolkit`, `WordPress\…`, `Throwable:`) is hidden by default.

### 4. "Up next" row layout fix

- The "Up next" row currently renders alongside the active stage as a sibling row with no margin from the active stage block. Add proper vertical spacing (use an existing margin token).
- Ensure the "Up next" row label (e.g. `URL treatment · Up next`) is the only place the next-stage name appears. The next stage's full content should NOT render twice when it transitions to active — currently when stage 3 (URL treatment) opens, the heading "URL treatment" appears both as the new active-stage label AND as the decision-card title beneath it. Either de-duplicate the heading or make the decision card use a subheading.

### 5. Calm "starting" placeholder

- For the first 2–5 seconds when no real progress data is available, the current action is a placeholder (`Starting`, `Queued`, etc.). In that state, the active stage card should show NO "This stage so far" panel (don't show empty/queued events as progress). Just the current-action line + a calm progress bar in indeterminate mode.

### 6. Spacing rhythm

- Keep the existing scale (≤ 7 font-sizes, ≤ 13 paddings, ≤ 12 margins). New rules use existing tokens.

## Acceptance checklist (every one [✓] with concrete evidence)

- [ ] **No fact appears more than twice on screen.** A screenshot at stage 1 should show "what's happening" stated in at most 2 places (the current-action line and the active stage detail — and even those should differ in phrasing). Evidence: side-by-side comparison with `running-1-1280.png` showing ≤ 2 restatements of the same fact, where "fact" is recognised by sharing > 50% bigram overlap.
- [ ] **Dedup groups by semantic key, not exact text.** Three events with messages "Queued to fetch GitHub repository files." / "Fetching repository files with sparse Git." / "Fetching repository files; file count appears after discovery." should produce ONE row in the user log. Evidence: snapshot HTML showing the rendered log + grep showing the new group-key map.
- [ ] **Recovered-failure diagnostics are hidden.** The `php-toolkit Git traversal failed for ref "trunk/docs"… will try the next GitHub path candidate.` line does NOT appear in the user activity log. It DOES appear in the Technical details disclosure. Evidence: snapshot HTML + grep.
- [ ] **"Up next" row has visible vertical margin** between the active stage block and the next-up row. Evidence: screenshot showing visible separation; grep showing the new margin rule.
- [ ] **"URL treatment" label not duplicated** when the URL-treatment decision opens. The active-stage card's heading and the decision card's heading don't both say "URL treatment". Evidence: screenshot of the URL-decision running state.
- [ ] **"This stage so far" panel is empty / hidden when there are no progress events yet** (queued-only state). Evidence: screenshot of the very-early state showing no panel + grep of the conditional.
- [ ] **Active stage detail is a short fragment**, not a full sentence repeated from the current action. Evidence: grep showing the detail-building logic + screenshot.
- [ ] **Composer test passes** ≥ 492 / ≥ 5794 (you may add tests for the dedup-group / diagnostic-filter logic).
- [ ] **verify-option-30-flow.js passes**.
- [ ] **Spacing rhythm preserved**: distinct counts of `font-size:`, `padding:`, `margin:` values within the same envelope as HEAD. Evidence: before/after grep counts.
- [ ] **No forbidden moves reintroduced**: no Server path, no Configure-the-run, no Edit-anything-above, no wp-admin blue inside the card.

## Iteration ritual (per implementer pass)

1. Read `render_session_list`, `render_dashboard_checklist`, `dashboard_checklist`, `dashboard_progress_summary`, `dedup_events`, `stage_for_event_type`, the JS twins (`groupEventsByStage`, `dedupEvents`), and the rendering for the active stage card + Up-next row + decision card.
2. Add or extend the dedup template registry (PHP + JS in sync).
3. Add the diagnostic-noise filter.
4. Fix the Up-next layout + URL-treatment heading collision.
5. Re-render snapshots:
   - default empty: `php tools/render-admin-snapshot.php`
   - running-early (queued, no progress): `php tools/render-admin-snapshot.php --running --scenario=stage-1-early`
   - running-stage-2 (already exists): `php tools/render-admin-snapshot.php --running`
   - URL-treatment decision: `php tools/render-admin-snapshot.php --running --scenario=stage-3-decision`
   - You may need to ADD `--scenario=stage-1-early` to the snapshot tool. Read what's there now and extend it.
6. Take screenshots into `.tmp/v6-shots/loop4-<n>/` (any short names — `early`, `mid`, `decision`).
7. Run `composer test` and `verify-option-30-flow.js`.

## Critic rules (for the verifier)

- The verifier MUST read the screenshots with the Read tool — PNGs render visually.
- For the dedup criterion, the verifier MUST identify in the screenshot at least one row where the count > 1 OR multiple semantically-equivalent events that previously stacked are now ONE row.
- For the "no fact twice" criterion, the verifier MUST count occurrences of the same meaning across the top status block and the active stage block in the screenshot, and fail if > 2.
- For "no diagnostic noise", the verifier MUST grep the snapshot HTML for the exact substring `Invalid Git ref` inside the user-facing log container, and fail if found.
- For the Up-next layout, the verifier MUST measure (or visually confirm) that there's a margin between the active-stage block and the Up-next row.

## Budget: 8 iterations. Stop on `VERDICT: PASS`.

# Prior verifier feedback
(none — first iteration)

# Instructions
Make real edits to src/Admin/ImportAdminPage.php and tools/render-admin-snapshot.php.
Address every prior-feedback issue.
Take screenshots of EVERY scenario (early / mid / decision).
Print a short bullet summary.
