# Task — STRICT cleanup, count every duplicate fact

Working dir: `/home/claude/wp-extensions-work/universal-wordpress-importer/`
Primary file: `src/Admin/ImportAdminPage.php`.

The previous loop's critic passed work that still contains obvious duplication. The user reported this VERBATIM after the prior round shipped:

### Bug A — Early stage still duplicates "queued from GitHub"

Quoted directly from the live admin:
```
https://github.com/WordPress/gutenberg/tree/trunk/docs
Starting · Publishes pages · Working

Queued to fetch GitHub repository files.

Stage 1 of 6 · Read source

Import stages
1  Read source  In progress
This stage so far
GitHub repository fetch queued; file count will appear after discovery.
2  Prepare content  Up next
```

Restated facts:
1. **"queued from GitHub"** said as `Queued to fetch GitHub repository files.` AND ALSO as `GitHub repository fetch queued; file count will appear after discovery.` That's TWO USER-FACING RESTATEMENTS OF ONE FACT.
2. **"Read source"** said as the standalone `Stage 1 of 6 · Read source` line AND as the active stage row `1 Read source In progress`. TWO RESTATEMENTS.

Even when grepping the static `--scenario=stage-1-early` snapshot today, "Queued to fetch GitHub" appears 2× and "Read source" appears 2×. **The previous loop's "PASS" was wrong.**

### Bug B — URL-treatment decision is buried + duplicated

Quoted directly from the live admin during the URL decision:
```
https://github.com/WordPress/gutenberg/tree/trunk/docs
Needs attention · Publishes pages

Choose URL treatment to continue.

Stage 2 of 6 · Prepare content · 133 of 255 items prepared (100%)

Needs attention
Answer the prompt below to continue the import.

Import stages
✓  Read source  Done  255 source items found.
2  Prepare content  In progress  Preparing 55 item.
This stage so far
12 / 257 documents converted to block markup
3  URL treatment  Up next
   Rewrite old-site URLs to this site?
   Selected hosts move to this site and keep the same paths…
   [host list]
   Rewrite selected (5)  Keep all URLs as-is  Don't ask again — keep all

Show all stages
Technical details
Abort
```

Restated facts in this state:
1. **"Needs attention"** appears in TOP meta line AND as the standalone `Needs attention / Answer the prompt below…` block. TWO RESTATEMENTS.
2. **"URL treatment"** appears as the active stage label hint AND as the row label `3 URL treatment Up next`. The decision card itself is buried under the "Up next" row instead of being the visual centerpiece.
3. **"55 item"** — missing plural "s". Grammatical bug.
4. **"Choose URL treatment to continue."** says the same thing the decision card heading says.
5. **`133 of 255 items prepared (100%)`** is internally inconsistent (133 of 255 is 52%, not 100%).

## What you MUST do

### A. Drop the standalone "Stage X of N · <stage name>" line entirely

It duplicates the active-stage row in the Import stages list. The progress bar already shows progress visually. Remove this line from `render_session_list()` and from the JS path (`updateSessionDom` / `renderSession`). After removal, the meta sequence is: `[source URL]` → `[status meta line]` → `[progress bar]` → `[current-action line]` → `[Import stages]`. One textual mention of the stage name (in the row), not two.

### B. Filter the "This stage so far" panel through the SAME noise filter as the user log

The panel currently renders events whose messages textually duplicate the current-action line. Either:
- (i) Hide the entire panel if every event in it would be filtered as noise, OR
- (ii) Filter individual events and hide the panel if zero remain.

Confirm by re-grepping `snapshot.html` after `--scenario=stage-1-early` that "GitHub repository fetch queued" appears EXACTLY ONCE (or zero) in the user-facing markup.

### C. Hoist the URL-treatment decision out of the stage list

When the back-end raises a `confirm-first-party-domains` decision, the URL-treatment row inside the Import stages list should be HIDDEN (because the decision card displays its content). The decision card itself should move OUT of the stage list and become its own block UNDER the active stage card (warm-amber accent surround, full width of the card column). It is the visual focus.

After the decision is resolved (per the existing flow), the URL-treatment row reappears in the stage list (eventually rolling up to `✓ URL treatment Done` with the persistent rewriting-URLs chips card already in place).

### D. Drop the standalone "Needs attention / Answer the prompt below to continue the import." block

When a decision is pending:
- The top meta line says `Needs attention · Publishes pages` — that's the one signal that something requires the user.
- The decision card itself is below, full and visible.
- No standalone block between them with "Needs attention / Answer the prompt below…" — that's the duplication.
- The current-action line ("Choose URL treatment to continue.") may also be dropped or shortened, since the decision card heading says the same thing. Pick one — recommend dropping the current-action line when a decision is pending.

### E. Fix pluralisation

`Preparing 55 item` → `Preparing 55 items`. Audit every count-interpolation site (`Preparing N item`, `N media items queued`, `N source items found`, etc.) and ensure they use `_n('item', 'items', $n, ...)` or PHP `( 1 === $n ? 'item' : 'items' )`.

### F. Fix the `133 of 255 items prepared (100%)` math bug

The percentage shown alongside "X of Y items" must be `round( X * 100 / Y )` and clamp to [0, 100]. Currently the code may be reading the overall `dashboard.percentage` (which is at 100 because of an upstream rounding bug) for the per-stage line. Per-stage progress is `count / total`.

## Hard constraints
- `composer test` must stay green (≥495 tests).
- `verify-option-30-flow.js` passes.
- No external resources, palette unchanged, no Server path / Configure-the-run / Edit-anything-above / wp-admin blue.
- Backend AJAX surface unchanged.
- Existing dedup helpers stay in place — you are extending the noise filter to the stage panel, not replacing the helper.

## Iteration ritual

1. Read the relevant code paths:
   - `render_session_list()` (PHP), find the `Stage X of N · ...` line and remove it.
   - `render_dashboard_checklist()` (PHP) and its JS twin, find where the active stage's "This stage so far" panel is built and ensure it applies `is_diagnostic_noise_event` AND a "duplicates the current action" filter.
   - The "Needs attention / Answer the prompt below..." block — find it (likely rendered as `attention_message` or similar field on the dashboard).
   - The decision-card render path — currently it's nested under the URL-treatment row; hoist it.
   - Plural interpolations — grep for `' item'`, `' source item'`, `' media item'`, `' document'` and audit each.
   - Per-stage percentage — find where the per-stage progress label is built and make it use `count/total`, not `dashboard.percentage`.

2. Re-render scenarios:
   ```
   php tools/render-admin-snapshot.php --running --scenario=stage-1-early
   cp snapshot.html /tmp/early.html
   php tools/render-admin-snapshot.php --running
   cp snapshot.html /tmp/mid.html
   php tools/render-admin-snapshot.php --running --scenario=stage-3-decision
   cp snapshot.html /tmp/decision.html
   ```

3. **Run the dedup grep yourself** and confirm:
   ```
   for f in /tmp/early.html /tmp/mid.html /tmp/decision.html; do
     echo "=== $f ==="
     grep -oE "Queued to fetch GitHub|GitHub repository fetch queued|file count will appear|Stage [0-9] of 6|Read source|Prepare content|URL treatment|Needs attention|Answer the prompt|Choose URL treatment to continue|Preparing [0-9]+ item " "$f" | sort | uniq -c | sort -rn
   done
   ```
   Every count must be **≤ 1** (or 0). If any count is 2 or higher, you have a duplication bug.

4. Screenshot each scenario at 1280px into `.tmp/v6-shots/loop5-<n>/`.

5. `composer test` and `verify-option-30-flow.js`.

## Acceptance checklist (every line MUST be [✓] with explicit grep evidence)

For each scenario `early`, `mid`, `decision`, grep the snapshot HTML body (between `<body>` and `</body>`, EXCLUDING the `<details class="universal-importer-pipeline">` block which is the Technical-details disclosure) and assert:

- [ ] **No "Stage X of N" line.** `grep -c "Stage [0-9] of 6" body-only.html` returns 0 (or ≤ 1 if you keep it on the progress-bar caption but nowhere else — pick one).
- [ ] **"Queued to fetch GitHub" appears at most once in `early`.** Currently 2.
- [ ] **"GitHub repository fetch queued" does NOT appear in `early`** (it's a queue-noise restatement). Currently 1.
- [ ] **"Read source" appears at most twice in `early`** (URL contains `/tree/trunk/docs` which is fine; the stage label is allowed once). Allowed in: URL string, active stage row. Not allowed in: standalone "Stage X" line. Currently 3+.
- [ ] **"Needs attention" appears at most once in `decision`.** Currently 2.
- [ ] **"Answer the prompt below to continue the import" does NOT appear in `decision`.**
- [ ] **"Choose URL treatment to continue." does NOT appear in `decision`** (drop OR replace).
- [ ] **"URL treatment" appears at most twice in `decision`** (allowed: active stage row when URL-treatment IS the active stage; decision card heading). Currently 3+ since the row label AND the decision-card label both render.
- [ ] **"Preparing 55 item" does NOT appear in any scenario** — use "items".
- [ ] **`133 of 255 items prepared (100%)` math is corrected** — if you see `X of Y items prepared (N%)`, then N must equal round(X*100/Y).
- [ ] **`composer test` passes**.
- [ ] **`verify-option-30-flow.js` passes**.

## Critic instructions

You MUST:
1. Regenerate `/tmp/early.html`, `/tmp/mid.html`, `/tmp/decision.html` via `php tools/render-admin-snapshot.php …` yourself.
2. Extract the body (between `<body>` and `</body>`) AND remove the Technical-details `<details class="universal-importer-pipeline">…</details>` block from your grep target. Many duplicates ONLY exist in Technical details (where they belong) — failing the check on the full HTML would be a false negative.
3. Run the grep audit yourself, one count per fact. If ANY fact occurs more than its allowed count, FAIL with a specific finding citing the line numbers.
4. Read at least ONE screenshot per scenario from `.tmp/v6-shots/loop5-<n>/` with the Read tool. Confirm visually that the UI doesn't restate facts.

Do NOT trust the implementer's claim that "X has been removed" — verify by grepping the rendered HTML yourself.

## Budget: 8 iterations. Stop on `VERDICT: PASS` only when every grep count passes.
