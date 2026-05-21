# Critic Notes 3: Universal Importer Journey Exploration

Artifact reviewed: `docs/importer/user-journey-exploration.html`  
Screenshots reviewed:
- `.tmp/ux-screenshots/final-desktop.png`
- `.tmp/ux-screenshots/final-mobile.png`

## Draft PR Coherence

The draft PR is now coherent enough for design discussion. The current artifact has a clear first-run story: choose a source, add a scoped source location, keep dry run as the recommended path, review conservative defaults, and understand that WordPress will pause after scanning when a decision is needed.

The latest pass resolves several earlier blockers:
- Mobile no longer opens with a tall sidebar before the task.
- The selected GitHub source now has a specialized source label and matching "Browse repositories" action.
- URL handling choices use native radio inputs.
- The current navigation item exposes `aria-current`.
- The progress meter uses a native `progress` element.
- The right rail is framed as an "After scan preview" instead of pretending a live import is already running.

This is the right level for a draft design PR: useful enough to discuss the journey and interaction model, but not final enough to evaluate detailed implementation behavior.

## Remaining Highest-Risk UX Issues

1. The artifact still compresses two moments into one screen.
   The "After scan preview" framing is much clearer, but desktop still shows setup and post-scan progress side by side. Reviewers may still read this as the actual runtime layout rather than as a journey preview. In the implementation, setup, scan progress, decisions, and dry-run results should be distinct states or steps.

2. The primary action remains late in the flow.
   "Start dry run" sits after source selection, source input, upload affordance, URL behavior, and run options. On mobile it will be several screens down. The flow is understandable, but the commitment point is still too far from the initial source task.

3. URL handling still has too much first-run weight.
   The recommended default is now good, but the setup screen still gives three URL handling cards before the scan has evidence. This can make URL rewriting feel like a required configuration decision. A safer model is one compact default in setup, then detailed keep/rewrite choices only after detected domains and affected counts are known.

4. The after-scan decision lacks impact.
   The decision names one URL, but not the number of affected pages, links, redirects, or imported items. A user cannot confidently choose "Rewrite selected URLs" without knowing the blast radius.

5. The journey still stops before the most important confirmation.
   The artifact shows setup and an in-progress scan decision, but not the dry-run result state. For design discussion, the next missing screen is the review result: what would be created, updated, skipped, warned, and what action starts the real import.

## Mobile And Accessibility Risks

Mobile:
- The compact top navigation is a major improvement, but the selected source cards still create a long first section before users reach the source input.
- The action remains too deep on narrow screens. A sticky or earlier review/start row would make the mobile task feel less like a long settings form.
- The status pill appears between the header and main task. It is harmless, but it adds another pre-task element on mobile.
- Long source URLs and detected URLs now wrap better, but they still need final checks in real browser text scaling and with longer repository paths.
- After a run starts, the mobile order should prioritize the pending decision above the full stage list and activity history.

Accessibility:
- Source selection uses `aria-pressed` buttons. That is acceptable for a prototype, but a radio-group model may better match the "exactly one source type" interaction.
- The source buttons include visible "Selected" text only on the active card; inactive options rely mostly on visual state. Ensure the final implementation announces selected/unselected state consistently.
- The stage dots expose symbols such as checkmarks and exclamation marks. The adjacent text helps, but the symbols should be hidden from assistive tech or replaced with explicit accessible labels.
- The upload area is visually drop-capable but is not connected to a file input in the static artifact.
- The decision checkbox names the URL but not the consequence or affected count.
- Color is not the only state indicator in most places now, but selected, warning, and progress states still need contrast verification against WordPress admin accessibility targets.

## Recommended Next Iteration

Make one journey-focused follow-up rather than another broad polish pass:

1. Split the artifact into three visible states: setup, after-scan decision, and dry-run result.
2. Move detailed URL rewrite choices out of setup; leave only "Review link changes after scan" as the default safety behavior.
3. Bring the "Start dry run" summary/action earlier or make it sticky on mobile.
4. Add impact details to the URL decision: affected pages, affected links, and a "View affected content" affordance.
5. Add a dry-run complete state that shows created/updated/skipped counts, warnings, and the next action to run the real import.

That iteration would make the PR much stronger for design review because it would demonstrate the full promise of dry run: not just starting safely, but making the real import decision from evidence.
