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
