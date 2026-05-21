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
- Pass 4 kept the dry-run result inspectable as a full table on desktop, but converts the same semantic rows into labeled card-style rows on mobile. This removes the narrow-screen horizontal-scroll dependency while preserving outcome, item, evidence, owner, and next-action visibility.
- Pass 5 added one compact, non-modal resolver inside the dry-run result. It uses the selected missing-media warning to show the missing path, chosen replacement, consequence, and two local actions without adding another top-level state or turning the static artifact into a full remediation app.
- Pass 5 kept the real-import state consequence-first and unchanged in structure: unresolved warnings remain explicitly allowed but carried forward, skipped content remains visible, and the primary action still says it runs as drafts.
- Pass 6 added the smallest post-import outcome proof inside the existing real-import state. The new "After running" block shows created draft links, updated draft links, the skipped item, and the remaining media warning with a final-report link.
- Pass 6 also aligned the final unresolved media count with the focused resolver example: after replacing one missing media file, the real import carries 1 unresolved media warning instead of 2.
- Pass 6 intentionally did not add recovery, retry, publish, or bulk-fix flows. Those would make the static artifact heavier than the current PR needs.
- Pass 7 tested a compact source-adaptation table inside the collapsed source-support disclosure, then removed it after critic review because it risked pulling the artifact back toward a capability matrix. The principle remains in notes: source type should adapt labels, examples, scan evidence, and warning copy without changing the four-step journey.
- Pass 7 intentionally kept the first screen unchanged: the visible setup still leads with source type, source input, Start dry run, and the dry-run guarantee before optional source details.
- Pass 8 did a narrow accessibility/clarity polish only. Generated badge dots remain decorative because adjacent badge text carries the status; stage symbols remain `aria-hidden` because each row now has explicit visible status text: Complete, Needs decision, or Not started.
- Pass 8 added programmatic selected text to the static selected source and URL-handling options while keeping the visible product surface unchanged. Native checked radio inputs still provide the main non-color selected state.
- Pass 8 labeled the setup badge as the current state and kept warning, complete, active, and allowed-with-warnings meanings in visible text rather than relying on color, dot shape, or icon shape.

## Remaining Concerns

- This is still a single static HTML artifact. A real implementation should route these as separate screens or replace lower states dynamically as the import advances.
- The source type radio switcher is static, so the selected source does not actually change the example label or input help. Production should adapt labels, examples, scan evidence, and warning copy to the selected radio value without changing the four-step journey.
- Browser upload is now behind a native disclosure to protect the first viewport. A production drag-and-drop target would still need keyboard, error, and accepted-format behavior.
- Counts and report details are illustrative. Real copy should come from actual scan result data and should handle localization, very long source names, and larger browser text.
- The mobile result cards now reduce density, but production should still test larger result sets and localized labels for scan speed, pagination, and repeated-action ergonomics.
- "Allowed with warnings" is shown as a static condition. Production should compute this from warning severity and disable the real import when blocking warnings remain.
- The new resolver demonstrates only one warning path. Production still needs the actual state model for replacement upload errors, duplicate-match changes, title confirmation, accepted warnings, and how resolved warnings update counts.
- The post-import block is deliberately tiny. Production still needs real edit/report URLs, write failure handling, permissions checks, and focus/live-region behavior after the import completes.
- This pass did not add live regions, focus management, interactive state changes, or high-contrast QA. Those remain implementation concerns beyond the static artifact.
