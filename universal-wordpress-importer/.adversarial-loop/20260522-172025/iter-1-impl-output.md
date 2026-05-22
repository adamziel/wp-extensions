Both pass. Summary of changes:

## Summary

**Type picker (lines ~1261–1410 CSS / ~2338–2406 HTML / ~2576–2700 JS in `src/Admin/ImportAdminPage.php`):**
- Replaced the tiny "amber pill + Change link" with a real select-style trigger button: inline-SVG icon on the left, label, chevron on the right. Hover, focus-visible, and open states are all visually distinct (amber border + amber soft fill + amber focus ring).
- Replaced the flat popover with a richer dropdown: 7 options, each with an inline-SVG icon, **bold title**, lighter one-line description, and a checkmark on the currently-selected option (plus soft-amber fill).
- Added `typeIcons` map + `renderTypePickerIcons()` + `setTriggerIcon()` + `markPopoverSelected()` JS helpers and wired them through `refreshInferredType()` and the popover click handler.
- Click handler now also writes the chosen `data-type` straight to the hidden `#universal-importer-state-source-type` input and restores focus to the trigger after close. Existing outside-click + Escape handlers preserved.
- No `Server path` option (verified: `grep -c 'data-type="Server path"'` returns 0).

**Directory selector (GitHub URLs only):**
- Replaced the muted "Path: repository root [change]" text line with a card row inside the cream card: folder SVG, kicker label "REPOSITORY PATH", mono path label, and a primary "Change folder" button (with folder icon).
- Button click still wires to `loadGithubDirectories()` — same existing modal, same skeleton (verified in screenshot).

**Tooling:**
- Added `b4-type-picker-open` state to `tools/screenshot-admin-flow.js` so the open dropdown is captured.
- Re-ran `php tools/render-admin-snapshot.php`, full 8-state screenshot batch, `composer test` (482 tests, 5753 assertions), `verify-option-30-flow.js` — all pass.

**Hard-constraint checks:**
- `composer test`: 482 tests, 5753 assertions, OK.
- `verify-option-30-flow.js`: pass; payload still contains `source=https://github.com/...` and `action=universal_importer_create_session`.
- No wp-admin blue (`#2271b1` / `#0073aa`) or external resources added in the diff.
- Loading skeleton still renders (`c-picker-loading` screenshot confirms).
- "Configure the run." headline and autofocused URL input untouched.
