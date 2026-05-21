# Critic Notes 2: Universal Importer Journey Exploration

Artifact reviewed: `docs/importer/user-journey-exploration.html`  
Screenshots reviewed:
- `.tmp/ux-screenshots/iteration-2.png`
- `.tmp/ux-screenshots/iteration-3-mobile.png`

## Draft PR Readiness

The artifact is strong enough to open as a draft design PR, with caveats. It now communicates a clearer importer model: choose a source, add one source input, keep dry run as the recommended path, and show where decisions happen after scanning. That is enough for design discussion.

It should not be presented as near-final UX. The remaining blockers are mostly journey ordering, mobile priority, and static accessibility semantics.

## Remaining Blockers And UX Risks

1. The first viewport still mixes setup with a post-start run state.
   - The rail label "After the dry run starts" helps, but the desktop screenshot still shows live progress, completed steps, and a pending URL decision beside a form that has not been started.
   - Risk: reviewers may focus on the artificial simultaneous state instead of the intended journey.
   - Next edit: make the right rail a setup summary by default, then show the progress rail as a separate "After scan" state below or in a second annotated panel.

2. The primary action is still too low.
   - In the desktop screenshot, "Start dry run" is below the visible area. On mobile it will be several screens down after sidebar, header, guidance, source cards, input, upload, and settings.
   - Risk: the page reads as configuration-heavy before the user sees the action or commitment level.
   - Next edit: add a sticky action row or move a compact "Review and start dry run" summary directly after the source input, with advanced behavior settings collapsed below.

3. Mobile ordering is currently dominated by navigation instead of the task.
   - The mobile screenshot spends the first screen on the dark sidebar and nav. The actual import content begins only after that.
   - Risk: mobile reviewers see a navigation shell, not an importer workflow.
   - Next edit: collapse the sidebar into a compact header on small screens, hide the prototype nav behind a menu, or move nav after the main task content.

4. Source type and source input remain partially in conflict.
   - GitHub is selected, but the input label still lists repository URL, site URL, feed URL, OPML URL, server path, and archive path.
   - Risk: choosing a source type feels cosmetic rather than functional.
   - Next edit: specialize the selected-state label to "Repository, branch, folder, or file URL" and keep universal paste support in helper text.

5. Link handling is improved but still appears too early.
   - "Ask when old URLs are found" is a better default, but the setup still asks about URL behavior before the scan has evidence.
   - Risk: this keeps URL handling as a first-run concern even though most users should defer until detected domains and affected counts are known.
   - Next edit: replace the URL handling card group with a simpler default line in run options: "Pause for detected old-site links." Move detailed keep/rewrite choices fully into the post-scan decision state.

6. The progress rail lacks impact details for the decision.
   - The decision shows one URL, but not how many pages, links, or imported items would change.
   - Risk: users cannot judge whether "Rewrite selected" is safe.
   - Next edit: add affected count and context, such as "Found in 3 pages / 7 links", plus a small "View affected content" action.

## Mobile-Specific Issues

- The sidebar consumes the top of the mobile view and delays the actual task.
- The "Ready" pill becomes a full-width row, which is acceptable, but it adds another pre-task element before source selection.
- The source cards will form a long list. The selected GitHub card starts below the screenshot fold, so users may not immediately see what is selected.
- The input plus "Browse GitHub" button stack correctly at mobile width, but the long example URL and long detected URL still need truncation/wrapping checks further down the page.
- The progress rail appears after the full setup flow on mobile. If the user has a pending decision, it may be buried unless the mobile order changes after a run starts.
- The action buttons in the decision state stack on mobile, which is fine, but "Rewrite selected" depends on a tiny checkbox above it. That selection model may be easy to miss on touch devices.

## Accessibility Issues Still Present

- The current sidebar link is styled with `.is-current` but does not expose `aria-current`.
- Source choices are buttons in a `role="list"` container, but selected state is only visual/textual. Use `aria-pressed`, or preferably a radio-group pattern if exactly one source can be active.
- URL handling cards are `label` elements without form controls. They are not keyboard-operable radio options in the static HTML.
- The progress meter is a styled `div` with only `aria-label`. It needs a native `<progress>` element or `role="progressbar"` with `aria-valuenow`, `aria-valuemin`, and `aria-valuemax`.
- The visual checkmark and exclamation symbols inside stage dots should be hidden from assistive tech or paired with clearer accessible labels; the visible status text helps, but the symbols are currently exposed as stray characters.
- The upload area is visually drop-capable but is not connected to a file input in the static HTML.
- The status pill uses a generated dot for state. The text "Ready" is present, but any future non-ready state should not rely on dot color alone.
- The decision checkbox label names the URL but does not communicate consequence or affected count.

## Concrete Next Edits

1. Replace the mobile sidebar with a compact top bar and move prototype navigation out of the first screen.
2. Make the right rail state-specific: setup summary before start, scan/progress after start, decision panel only when scan evidence exists.
3. Bring "Start dry run" into the first desktop viewport and within the first two mobile screens.
4. Specialize the source input label and helper action based on the selected source type.
5. Collapse setup URL behavior into the dry-run safety defaults, then keep detailed URL decisions in the post-scan panel.
6. Add affected counts and a "View affected content" affordance to the URL decision.
7. Convert source and URL handling card selections to accessible form controls or ARIA button states.
8. Replace the progress meter `div` with semantic progress markup.
9. Add one alternate state below the main artifact: "Dry run complete, no blockers" or "Dry run complete with warnings." This would make the journey feel less centered on a single pending URL decision.

## Recommendation

Open as a draft design PR after one more pass on mobile ordering if time allows. If the team needs feedback now, it is acceptable to open immediately as a draft, but the PR description should explicitly say that mobile layout and accessibility semantics are unresolved.
