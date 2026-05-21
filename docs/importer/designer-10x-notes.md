# Universal Importer 10x Clarity Notes

- Created `user-journey-10x-clarity.html` as a new static exploration instead of mutating the merged PR #27 artifact.
- Made the first screen action-first: "Add source" is the main heading, the source input appears immediately, and "Start dry run" is available before source explanation cards or status-heavy chrome.
- Replaced large source cards as the primary chooser with a compact radio switcher. The full source set remains discoverable in a collapsed "What each source supports" section so GitHub, WordPress site, feed/OPML, server path, browser upload, and archive/document imports are still visible without turning setup into a catalog.
- Kept safety defaults concise: dry run first, save drafts, ask before URL rewriting, and report duplicates. Detailed URL handling is deferred until the scan has affected counts and examples.
- Split the full journey into four static states: setup, scan decision, dry-run result, and ready for real import.
- Improved the scan decision state by putting the blocking URL handling decision first, then the progress/status history. This ordering is especially important on mobile.
- Added explicit dry-run result reporting for would-create, would-update, skipped, warnings, duplicate matches, missing media, and skipped untitled content.
- Added a final real-import-ready state that repeats exactly what will be written: drafts created, existing drafts updated, old-site URLs kept external, skipped items left unchanged, and warnings retained.
- Preserved WordPress-admin-adjacent styling: compact panels, system fonts, WordPress blues, restrained borders, native inputs, no JavaScript, and no external dependencies.
- Used credible static accessibility semantics: labeled fields, native radio groups, checkboxes, native file input, progress element with visible text, state headings, and long URL/path wrapping.
- Pass 2 tightened the mobile top navigation into four equal state links with short labels, avoiding the previous horizontal scrollbar while preserving direct access to all four journey states.
- Pass 2 made the first mobile viewport more action-first by hiding nonessential title chrome, ordering the source input before the source-type chooser, and moving browser upload into a native disclosure after the primary dry-run action.
- Kept desktop calm by retaining the full source-type switcher, two-column setup/readiness layout, and collapsed upload affordance instead of adding more visible source cards or options.
- Pass 3 converted "Dry run first" from an editable checkbox into a fixed first-run guarantee placed directly under the primary dry-run action and repeated in the setup summary. The remaining editable settings are drafts, URL rewriting, and duplicate reporting.
- Pass 3 made the dry-run result inspectable with semantic result counts and a compact table of item outcomes, including create/update/skip/warning state, duplicate evidence, warning ownership, and direct paths to review matches, replace missing media, or add/confirm a title.
- Pass 3 changed the real-import state to consequence-first copy: the primary action says it will run as drafts, the panel explicitly says publishing count is zero, unresolved warnings are allowed but non-blocking, and skipped/unresolved items remain visible before the green action.
- Pass 3 tightened static semantics by connecting help text with `aria-describedby`, removing redundant radio-group labeling around the native source fieldset, and exposing result counts as list items plus detailed outcomes as a table.

## Remaining Concerns

- This is still a single static HTML artifact. A real implementation should route these as separate screens or replace lower states dynamically as the import advances.
- The source type radio switcher is static, so the selected source does not change the example label or input help. The production version should update labels and examples per source type.
- Browser upload is now behind a native disclosure to protect the first viewport. A production drag-and-drop target would still need keyboard, error, and accepted-format behavior.
- Counts and report details are illustrative. Real copy should come from actual scan result data and should handle localization, very long source names, and larger browser text.
- The table-like dry-run result intentionally favors inspection density. A production mobile implementation may need row cards or column controls instead of horizontal table overflow.
- "Allowed with warnings" is shown as a static condition. Production should compute this from warning severity and disable the real import when blocking warnings remain.
