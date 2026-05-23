# Task — type picker and directory selector polish

Working dir: `/home/claude/wp-extensions-work/universal-wordpress-importer/`
Single file to edit: `src/Admin/ImportAdminPage.php` (inline CSS + HTML + JS, no external resources).

## What we're redesigning

The Source step of the importer admin (`Tools → Universal Importer`) currently shows, once a URL is pasted:

1. A small amber pill that reads e.g. "GitHub repository" with a "Change" text link next to it.
2. For GitHub URLs only, a line "Path: repository root [change]" — the [change] is a small text link that opens the existing directory picker modal.

Both feel underweighted next to the rest of the form. Redesign them so:

### 1. Content-type picker (every URL state)

- Looks like GitHub's PR-merge-mode dropdown / a select2-styled enhanced select.
- Closed trigger: a button-with-chevron that shows the current selection's **icon + label**. Looks unmistakably clickable on hover/focus.
- Opens a richer dropdown listing every type with:
  - small inline-SVG icon per option,
  - bolder title,
  - one-line description (lighter, smaller),
  - a clear "selected" indicator on the current type (checkmark or amber rail / soft-amber background — pick one and commit).
- Closes on outside click or Escape.
- Override list (must NOT include "Server path"): GitHub repository, WordPress site, RSS / Atom feed, Sitemap, WXR XML export, Remote HTML page, OPML feed list.
- Selecting a type still writes to the hidden `source_type` input so the backend gets it.

### 2. Directory selector (GitHub URLs only)

- Reads as a friendly, discoverable affordance — not a phrase with a tiny text link.
- Show a folder glyph + the currently selected path label prominently.
- A clear primary action ("Change folder" or "Browse" — pick one and commit) that opens the existing modal.
- When the user selects a directory the label updates inline; nothing else moves.
- Loading skeleton in the modal must still work as it does now.

## Hard constraints
- `composer test` must stay green (482 tests, ≥5731 assertions). Update test assertions only when DOM markers genuinely change shape.
- No external resources (no fonts, no remote images, no svg fetches; inline SVG ok).
- Behavior preserved: the hidden `source_type` input is still written; the existing GitHub modal still opens and uses `loadGithubDirectories()`; submitting still goes through the same AJAX path.
- The whole card stays within the existing palette (warm cream card `#fbf8f1`, deep amber primary `#a16207`, no wp-admin blue introduced here).
- Headless verifier passes: `node tools/verify-option-30-flow.js /run/current-system/sw/bin/chromium`.

## Iteration ritual
Each implementer pass:
1. Edit `src/Admin/ImportAdminPage.php` (and tests if assertions need shape updates).
2. Run `php tools/render-admin-snapshot.php`.
3. Run `node tools/screenshot-admin-flow.js /run/current-system/sw/bin/chromium .tmp/v6-shots/loop-<n> 1280` to refresh the seven flow screenshots.
4. If you need a new screenshot state (e.g. the picker open / the path popover), edit `tools/screenshot-admin-flow.js` to add a state and re-run.
5. Run `composer test` and `node tools/verify-option-30-flow.js /run/current-system/sw/bin/chromium`.

Each verifier pass:
1. Read the latest screenshots under `.tmp/v6-shots/loop-<n>/` directly with the Read tool (PNG files render visually). The states are `a-empty`, `b-url-typed-github`, `b2-url-typed-wp`, `b3-url-typed-feed`, `c-picker-loading`, `d-configure`, `e-confirm`. Add a state for the opened type-picker dropdown if it isn't yet captured.
2. Re-run `composer test` yourself and `node tools/verify-option-30-flow.js /run/current-system/sw/bin/chromium` yourself.
3. Inspect the live DOM by grepping `src/Admin/ImportAdminPage.php` for the new selectors and reading the changed CSS rules — assert per-criterion below.

## Acceptance criteria (every one must be [✓] with concrete evidence)

- [ ] **Type picker closed state** shows current selection's icon + label, plus a chevron, and has a visible focus ring and clear hover state. Evidence: screenshot of `b-url-typed-github` and one of `b2-url-typed-wp`.
- [ ] **Type picker open state** lists 7 options. Each option has an inline-SVG icon, a bold title, and a one-line description. The current selection is unambiguously marked (checkmark or filled background). Evidence: screenshot of the open dropdown state (verifier may need to add a screenshot state).
- [ ] **Type picker behavior**: clicking the trigger opens the dropdown; clicking outside closes it; Escape closes it; selecting an option closes it and updates the trigger and the hidden `source_type` input. Evidence: screenshot showing the open state + grep showing the new outside-click / Escape handlers + verify-option-30-flow.js result.
- [ ] **No "Server path"** in the override list. Evidence: `grep -c 'data-type="Server path"' src/Admin/ImportAdminPage.php` must return 0.
- [ ] **Directory selector** shows a folder glyph (inline SVG), the current path label as the primary visual element, and a clear primary action button labeled "Change folder" or "Browse". Evidence: screenshot of `b-url-typed-github`.
- [ ] **Directory selector action** opens the existing modal which still calls `loadGithubDirectories()`. Evidence: grep showing the click handler still routes through `loadGithubDirectories` + screenshot of `c-picker-loading` showing the modal + skeleton.
- [ ] **Loading skeleton intact** in the picker modal. Evidence: screenshot of `c-picker-loading`.
- [ ] **`composer test` PASSES** with ≥482 tests and ≥5731 assertions. Evidence: tail of the test command run by the verifier.
- [ ] **`verify-option-30-flow.js` PASSES**, captured payload still contains `source=<the URL>` and `action=universal_importer_create_session`. Evidence: tail of the verifier command output.
- [ ] **Palette discipline**: no new wp-admin blue introduced in the changed regions. Evidence: search for `#2271b1` / `#0073aa` in the diff.
- [ ] **No external resources** added. Evidence: search for `<link rel="stylesheet"`, `googleapis`, `cdn.`, `http://`, `https://` in the diff outside of demo URL strings.
- [ ] **Tidiness preserved**: card stays single-card, no new framed boxes, no headlines reintroduced. Evidence: comparison of `a-empty` screenshot to the previous baseline at `.tmp/v6-shots/iter6-1280/a-empty-1280.png`.

## Forbidden moves
- Reintroducing the "Configure the run." headline.
- Reintroducing "Server path" anywhere in the UI.
- Adding wp-admin blue inside the importer card.
- Removing the loading skeleton.
- Removing the autofocused URL input.

## Budget
Up to 10 iterations.
