# Universal Importer UX Design Notes

- Reframed the first screen around the user question: "what are you importing?" Source choices now explicitly cover GitHub, WordPress site, feed/OPML, server path, browser upload, and archive/document imports.
- Reduced competing panels by making setup a single primary flow and keeping progress, decisions, and activity in a narrower status rail.
- Added stronger first-run guidance: dry run is presented as the recommended path, with a three-step expectation before the form.
- Clarified labels around URL handling, drafts, dry run behavior, and source input so advanced formats remain available without making the start state feel like a checklist.
- Kept the visual language restrained and WordPress-admin-adjacent: white panels, WP blues, compact controls, simple borders, and no extra scripting.

## Pass 2

- Collapsed the tall mobile sidebar into a compact title plus horizontal section nav so the source task appears much sooner on narrow screens.
- Removed visible prototype-facing language and reframed the right rail as an after-scan preview instead of a simultaneous current import.
- Specialized the selected GitHub source field with a repository/folder/file URL label and "Browse repositories" action while keeping universal paste support as secondary help text.
- Added static accessibility semantics for selected source state, real radio inputs inside URL-handling cards, `aria-current` on the active nav item, and a native progress element with values.
- Remaining concern: the static artifact still shows setup and a future scan example on one page; the labels now separate the states, but an implementation should route these as distinct screens or steps.

## Pass 3

- Replaced the initial right rail with a setup summary: selected source, scope, dry-run safety, old-link pause behavior, and a short "what happens next" sequence.
- Moved the progress meter, stage list, link decision, activity log, and affected-link example into a clearly separated lower "After dry run starts" state panel.
- Moved the primary "Start dry run" action directly under the source input, before optional browser upload and safety defaults, so it appears earlier on desktop and is reached sooner on mobile.
- Simplified first-run settings into safety defaults and deferred detailed URL rewrite choices until the after-scan decision state has affected counts.
- Preserved pass 2 accessibility improvements: selected source state remains exposed with `aria-pressed`, the active nav keeps `aria-current`, the progress example uses native `<progress>`, and stage symbols are hidden from assistive tech while status text remains visible.
- Remaining concern: this is still a static single-page artifact, so a production flow should route setup and after-scan states as separate screens or replace the lower example with an actual state transition.

## Pass 4

- Reworked the mobile source path so the selected GitHub source summary and URL input appear before the full source chooser, while the alternate source types remain available as compact cards.
- Renamed the former source-input step to optional browser upload because the primary paste field now belongs with the selected source.
- Added a separate dry-run result state after the scan decision example with would-create, would-update, skipped, and warning counts plus a primary action to run the real import.
- Kept setup, scan decision, and dry-run result states visually distinct through separate panels, different state labels, and a green result header.
- Preserved docs-only scope and static accessibility semantics; no JavaScript or external dependencies were added.
- Remaining concern: the selected source is duplicated in the compact alternate-source chooser so users can still see the full source set; an implementation should make this an actual segmented or searchable chooser.
