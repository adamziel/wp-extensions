All checks green. Here's what changed:

**Spacing normalization** (`src/Admin/ImportAdminPage.php`)
- `gap: 9px → 8px` in `.universal-importer-opt` and `.universal-importer-option`
- `gap: 5px → 6px` in `.universal-importer-opts` and `.universal-importer-paused-chip`
- `gap: 14px → 12px` in `.universal-importer-strip-row` and `.universal-importer-tally`
- `margin-top: 10px → 12px` in `.universal-importer-inferred`, `.universal-importer-log`, `.universal-importer-tally`, `.universal-importer-decision`
- `margin-top: 14px → 12px` in `.universal-importer-actions`
- `margin-top: 3px → 2px` in `.universal-importer-domain-list input`
- `margin-bottom: 4px → 6px` in `.universal-importer-log li`
- `margin-bottom: 18px → 16px` in `.universal-importer-past`
- `margin: 0 0 10px → 0 0 12px` in `.universal-importer-checklist`
- `padding-top: 10px → 12px` in `.universal-importer-log`

**Clarity / a11y**
- Type-picker option `:focus-visible` now has a distinct deep-amber ring (`box-shadow: 0 0 0 2px var(--ui-accent-deep)`) instead of inheriting the hover background
- Added `@media (prefers-reduced-motion: reduce)` rule that disables the turn fade-in and the prominent-button hover lift

**Screenshot tool determinism** (`tools/screenshot-admin-flow.js`)
- Pass `--force-prefers-reduced-motion` to chromium so the e-confirm capture no longer lands mid-fade — the confirm state now shows a crisp dominant Start import button

**Final audit set sizes** (≤ targets)
- font-size: 6 distinct (≤ 8) ✓
- padding: 10 distinct (≤ 10) ✓
- margin: 12 distinct (≤ 15) ✓
- gap: 8 distinct (down from 11)

**Constraint checks**
- `composer test`: 483 tests, 5765 assertions — OK
- `verify-option-30-flow.js`: Verdict: pass
- `api.github.com` in admin: 0 occurrences
- No external resources / fonts / Server path / Classify step
