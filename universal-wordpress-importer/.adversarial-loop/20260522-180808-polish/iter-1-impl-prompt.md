# Task
# Task — polish pass on size consistency, clarity, and ease

Working dir: `/home/claude/wp-extensions-work/universal-wordpress-importer/`
Primary file: `src/Admin/ImportAdminPage.php` (inline CSS + HTML + JS).
Baseline screenshots before this loop: `.tmp/v6-shots/baseline-before-loop2-1280/`.

The importer admin is already redesigned to a clean GitHub-style picker + a card-sized directory selector. This loop polishes the remaining roughness without re-litigating earlier decisions. Read the baseline screenshots first to understand the current state.

## Three dimensions the critic must enforce

### 1. Size consistency (type & space rhythm)

- Audit every font-size, font-weight, line-height, and margin in the importer card. Identify the type scale actually in use and confirm it's a small, deliberate set (e.g. 23 / 16 / 14 / 13 / 12 / 11 px — not 14.5/13.5/12.5 mixed with rounded ones).
- Audit spacing: vertical rhythm between adjacent elements should be predictable (e.g. 6/12/24 px stack). No arbitrary 7px, 9px, 13px gaps unless intentional.
- Audit padding inside cards/buttons: same family of values everywhere.
- The new type-picker dropdown, the directory selector card, the URL input, the support line, the file-pick links — all should look like they're from the same design system, not separate components welded together.

### 2. Clarity of every interaction

- For each state, identify exactly one primary action. The primary action must be the visually most prominent thing. Secondary/tertiary actions must be obviously secondary (quieter color, lighter weight, less surface).
- The "Past imports" link top right: is it discoverable? Does it read as a navigation away from the active task, not an action that affects the current import?
- The type-picker trigger: hover/focus/open states. Does opening feel right?
- The "Change folder" affordance: discoverable without dominating? Should the whole REPOSITORY PATH card be clickable, or only the button?
- The Source step's URL field has autofocus — confirm a user can paste, see the inferred type appear, and reach the Next button with a logical tab order.
- "Choose file · Choose folder · or drop a file here" — does this feel like a fallback or like a primary path? Should it look quieter relative to the URL input?
- Configure step: URL TREATMENT radios — does the recommended one stand out? Are the supporting hints below each radio readable but not louder than the title?
- Confirm step: "Ready to import." + Start import + Back. The 3 past-row summaries above — are they readable, scannable, not noisy?

### 3. Ease of use

- Keyboard: every interactive element reachable via Tab; visible focus styles on every interactive element; Enter advances the focused primary CTA at each step; Escape closes the type-picker popover AND the GitHub directory modal.
- Hover affordances: every clickable thing changes appearance on hover.
- The drop affordance on the Source card: dragging files over the card should highlight (already wired) — verify the highlight is intentional and quiet.
- The directory picker modal: skeleton shimmer + clear empty/error states. After loading, the directory list should be readable and the Use directory button should be inactive until something is selected.
- Mobile/tablet: at 768px the layout should still hold. (Verifier should screenshot at 768 as well.)

## Hard constraints (carry forward from earlier rounds)
- `composer test` stays green (≥483 tests, ≥5765 assertions).
- `node tools/verify-option-30-flow.js /run/current-system/sw/bin/chromium` passes.
- No `api.github.com` reintroduced in the admin path.
- No external resources (fonts, remote images, CDNs).
- No `Server path` reintroduced.
- Palette stays within the warm-cream card + deep-amber primary. No wp-admin blue inside the importer card.
- All existing tests' DOM-marker assertions stay intact (you may add new assertions but don't relax existing ones).

## Iteration ritual
Per implementer pass:
1. Make targeted edits.
2. Run `php tools/render-admin-snapshot.php`.
3. Run `node tools/screenshot-admin-flow.js /run/current-system/sw/bin/chromium .tmp/v6-shots/polish-<n>-1280 1280`.
4. Run `node tools/screenshot-admin-flow.js /run/current-system/sw/bin/chromium .tmp/v6-shots/polish-<n>-768 768` for tablet.
5. Run `composer test` and `node tools/verify-option-30-flow.js /run/current-system/sw/bin/chromium`.

Per critic pass:
1. Read every screenshot directly via the Read tool — they render visually.
2. Specifically compare polish-<n> screenshots to the baseline-before-loop2 screenshots.
3. Re-run tests + verifier yourself.
4. Audit the diff in `src/Admin/ImportAdminPage.php` for type/space consistency by grep:
   - `grep -oE 'font-size:[^;]+' src/Admin/ImportAdminPage.php | sort | uniq -c | sort -rn`
   - `grep -oE 'padding:[^;]+' src/Admin/ImportAdminPage.php | sort | uniq -c | sort -rn`
   - `grep -oE 'margin:[^;]+' src/Admin/ImportAdminPage.php | sort | uniq -c | sort -rn`
   These should reveal a tight set of values, not a sprawl.

## Acceptance checklist (every line must be [✓] with concrete evidence)

- [ ] **Font-size set** is ≤ 8 distinct values across the importer card. Evidence: `grep -oE 'font-size:[^;]+' src/Admin/ImportAdminPage.php` summary.
- [ ] **Padding set** is ≤ 10 distinct values. Evidence: grep summary.
- [ ] **Margin set** is ≤ 15 distinct values. Evidence: grep summary.
- [ ] **Each flow state has exactly one visually dominant primary action.** Evidence: per-state screenshot annotation.
- [ ] **Visible focus rings** on every interactive element (URL input, type-picker trigger, popover options, Change-folder button, Next, Back, Choose file/folder links, Clear selection). Evidence: a new screenshot state showing each focused (or `:focus-visible` CSS rule audit).
- [ ] **Type picker behavior**: trigger click toggles open/close; outside click closes; Escape closes; selection updates trigger + hidden source_type; Tab from URL input reaches the trigger. Evidence: screenshots of open state + verify-option-30-flow run.
- [ ] **Directory selector** has a clear primary action ("Change folder") and the whole card is intentionally NOT a button (or it IS a button — pick one and commit). Evidence: screenshot + CSS.
- [ ] **Modal Escape closes** the GitHub directory picker. Evidence: a brief grep showing the Escape handler binds to the modal.
- [ ] **Tab order makes sense** through the Source step. Evidence: trace `tabindex` attributes and DOM order.
- [ ] **Tablet 768px holds**. Evidence: screenshots at 768.
- [ ] **`composer test` passes** (≥483 / ≥5765). Evidence: tail of command output.
- [ ] **`verify-option-30-flow.js` passes**. Evidence: tail of command output.
- [ ] **No `api.github.com` in admin path**. Evidence: `grep -c 'api.github.com' src/Admin/ImportAdminPage.php` == 0.
- [ ] **No external resources or new fonts**. Evidence: search the diff for `link rel`, `googleapis`, `cdn.`, `@import`, `@font-face`.

## Forbidden moves (carried forward)
- Reintroducing "Configure the run." headline.
- Reintroducing "Server path" anywhere.
- Reintroducing wp-admin blue inside the card.
- Removing the loading skeleton.
- Removing autofocus on URL input.
- Reintroducing the Classify step.
- Reintroducing the Edit link on past summaries.

## Budget
Up to 8 iterations. Stop earlier on `VERDICT: PASS`.

# Prior verifier feedback
(none — first iteration)

# Instructions
Implement the task above. Make real edits to src/Admin/ImportAdminPage.php.
If there is prior feedback, address every issue listed.
Run the iteration ritual described in the task.
Print a short bullet-list summary of what changed.
