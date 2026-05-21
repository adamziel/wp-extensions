# Critic Notes 4: Universal Importer Journey Exploration

Artifact reviewed: `docs/importer/user-journey-exploration.html`  
Screenshots reviewed:
- `.tmp/ux-screenshots/pass3-desktop.png`
- `.tmp/ux-screenshots/pass3-mobile.png`

## Good Enough For The Draft PR

The artifact is now good enough to support the draft PR's core discussion: how a cautious first import should start, what the dry-run promise means, and how WordPress should defer risky decisions until there is scan evidence.

The latest committed pass fixes the main journey ambiguity from the previous review. Setup now has its own summary rail, while the scan/progress/decision example is explicitly presented as a second state below the setup flow. That makes the artifact much less likely to be read as a single overloaded runtime screen.

The primary action is also in a better place. "Start dry run" now appears immediately after the source URL, before upload fallback and safety defaults, which makes the happy path visible once the user has supplied the minimum required input. The setup summary reinforces the same model with "Ready for a dry run" and a clear "What happens next" list.

The URL handling model is coherent enough for draft review. Setup uses a compact safety default, and the detailed rewrite/keep decision appears only after the scan has evidence, affected counts, and a "View affected content" affordance.

## Highest-Value Next Edit

The next highest-value edit is to add, or at least sketch, the dry-run result state. The current artifact shows setup and an in-progress decision, but it still stops before the moment that proves the dry run was useful: the final evidence summary that lets a user decide whether to run the real import.

That state should answer:
- What would be created, updated, skipped, or left untouched.
- Which warnings remain unresolved.
- Which duplicate or URL decisions affected the result.
- What action starts the real import, and whether it will publish or save drafts.

This matters more than another setup polish pass because the PR's strongest idea is evidence before writing. The final dry-run result is where that promise either becomes credible or stays abstract.

## Mobile-Specific Risks After Pass 3

Mobile is improved, but the first viewport still contains a lot before the user reaches the required source input: app nav, page title, status, recommendation copy, step chips, and six source cards. The two-column card layout at this width is efficient, but it still pushes "Add the source" below the fold in the reviewed screenshot.

The earlier desktop action does not fully solve the mobile action depth. On narrow screens, "Start dry run" is still several sections down because source selection consumes the first task screen. If this becomes a real flow, consider either collapsing non-selected source types after a choice or making the current source selector more compact once a default is selected.

The mobile order after scan also needs implementation attention. When a scan needs input, the decision should appear before a long progress stage list or activity history. The current lower panel is acceptable as an exploration artifact, but the real responsive state should prioritize the blocking decision.

Long source URLs and affected-content labels are now using better wrapping patterns, but they still need checks with longer repository paths, larger browser text, and localized strings. The source cards are especially likely to vary in height once labels are translated.

## Accessibility Issues That Still Matter

The source selector is still modeled as `aria-pressed` buttons. That can work for a prototype, but the implemented control is semantically an exclusive choice. A radio group, segmented control with correct roles, or another pattern that announces one selected value would be clearer to assistive technology users.

The "Selected" marker appears only inside the active source card. The final implementation should ensure every source option has an announced selected/unselected state, not just visual border and background treatment.

The step chips in the recommendation area look like navigation but are static spans. If they become clickable, they need button/link semantics and focus states. If they remain informational, the implementation should avoid making them feel like disabled controls.

The upload panel describes dropping files, but the static artifact does not show a real file input relationship. Implementation should connect the visible control, drop target, keyboard path, accepted file messaging, and error states.

The progress meter uses a native `progress` element, which is good. The real implementation should add live-region behavior for meaningful state changes, especially when the dry run pauses for a decision.

The decision panel has affected counts now, which is a major improvement. The remaining implementation issue is making the consequence of each action explicit to screen reader users: "keep external" versus "rewrite selected URLs" should announce what will happen to the affected pages and links.

## Recommendation

Pause broad iteration and ask for human feedback on this draft PR. The artifact is now coherent enough to evaluate the direction, and another visual pass risks optimizing details before reviewers confirm the journey model.

If the team wants one more focused edit before feedback, make it the dry-run result state only. Do not spend the next pass on source-card polish, spacing, or copy tweaks unless reviewers specifically ask for them.
