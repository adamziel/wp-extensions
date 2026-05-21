# Critic Notes: Universal Importer Journey Exploration

Artifact reviewed: `docs/importer/user-journey-exploration.html`  
Screenshot reviewed: `.tmp/ux-screenshots/iteration-0.png`

## Bottom Line

The prototype is moving in the right direction: it creates a visible sequence, separates source choice from link behavior, and exposes a pending decision instead of burying it. The main problem is that it still feels like two products at once: a start-import wizard and a live import monitor compete for attention on the same screen before the user has even started.

The journey should become more linear, more evidence-led, and less explanatory. The importer should first help the user answer: "What am I importing?", "What will happen?", "What needs my decision?", and "What changed?" Right now those questions are present, but mixed together.

## Highest-Impact Issues

1. The page shows setup and an in-progress import at the same time.
   - This creates a broken mental model: the left side says "Start dry run"; the right side says a dry run is already 9 of 17 tasks complete and needs input.
   - Recommendation: show either the setup state or the running state as the dominant journey. If this artifact needs to demonstrate both, label them as separate states or stack them as a before/after sequence.

2. The primary action is below the fold.
   - In the screenshot, step 4 and the "Start dry run" button are mostly hidden at the bottom. The user can configure a lot without seeing the consequence.
   - Recommendation: keep the primary action visible earlier, either as a sticky footer within the setup panel or as a compact summary/action row after step 2 or 3.

3. Explanatory copy is doing too much work.
   - Lines like "The form adapts..." and "One primary field keeps..." explain the prototype strategy, not the user task.
   - Recommendation: remove prototype rationale from the UI. Replace with task language such as "Choose a source type to show the right import options" and "Paste a URL or choose files."

4. Source selection and source input conflict.
   - The user picks "GitHub repository", but the label still accepts GitHub URL, site URL, feed URL, server path, or archive path. That weakens the value of source selection.
   - Recommendation: after source selection, specialize the input label and helper action. For GitHub: "Repository, branch, folder, or file URL" plus "Browse repositories". Keep universal paste support as secondary help text, not the main label.

5. Link handling appears too early and too abstract.
   - The current step asks the user to choose link behavior before the importer has found any domains. Then the progress panel asks a more specific URL decision later.
   - Recommendation: compress setup link behavior into one default: "Ask me about old-site links after scanning." Move detailed rewrite choices to the review/decision state when detected domains are known.

## What To Remove, Compress, Rename, Or Reorder

Remove:
- Sidebar text "Prototype artifact for a clearer import journey." It is not product UI.
- Most strategy copy in step descriptions. It increases cognitive load without helping the import.
- The duplicate "Decisions" nav item if decisions only appear inside current import. It reads like a separate area but anchors into a subsection.

Compress:
- Step 1 cards can be shorter. The labels are useful; the format lists are dense.
- Step 3 should become a compact "Safety options" section with dry run and drafts. Link rewriting should move later.
- Recent activity should be lower priority than the pending decision. It should not compete visually with the decision box.

Rename:
- "Pick where the content is coming from" -> "Choose source type".
- "Add the source" -> "Paste a URL or choose files".
- "Choose how imported links should behave" -> "Review link changes after scan" if kept in setup, or remove from setup.
- "Start with a clear expectation" -> "Review and start dry run".
- "URL treatment" -> "Decide link handling".
- "Keep external" -> "Keep as external links". The current label is terse and ambiguous.
- "Rewrite selected" -> "Rewrite selected URLs to this site".

Reorder:
- Put source type, source input, and dry-run/draft safety settings first.
- Then show a compact preflight summary: source, mode, destination status, expected content types.
- Then start dry run.
- After scanning, show findings and required decisions: detected domains, content count, media count, errors/warnings.
- Only then ask specific link rewrite questions.

## Missing States

- Empty/new user state: no recent import, no selected source, no example URL.
- Invalid source state: unsupported URL, private GitHub repo, unreachable site, bad archive, missing permissions.
- Authentication state: GitHub private repo, WordPress REST auth, server path permissions.
- Scan complete with no decisions needed.
- Scan complete with warnings but no blockers.
- Decision skipped/deferred state.
- Dry run complete state with "what would be created/updated/skipped".
- Real import confirmation after dry run.
- Import paused, resumed, failed, canceled, and retried.
- Duplicate content handling: create new, update existing, skip, or map to existing pages.
- Media handling states: download failed, large file, unsupported type, duplicate asset.
- Long-running import state with time estimate and background-safe messaging.
- Post-import result state with links to created drafts/pages and an error report.

## Hidden Assumptions

- Assumes users understand "dry run". Many will not. Explain it where the action appears: "Preview changes without creating pages."
- Assumes "GitHub sparse checkout" is meaningful to end users. It is implementation language; replace with "Selected GitHub folder" or hide it in technical details.
- Assumes link rewriting is the main hard decision. Content mapping, duplicates, media, author/status, and destination type may be equally important.
- Assumes the destination is obvious. The UI says "this WordPress site" but does not name the site, environment, or content type target.
- Assumes a URL is enough to know scope. GitHub repo imports need branch/folder/file scope; WordPress site imports need post types and authentication; archives need content format detection.

## Feature Discoverability

- The supported formats are visible but crammed into card descriptions and upload helper text. Users may miss that WXR, Markdown, HTML, PDF, EPUB, folders, and ZIPs are supported.
- "Browse GitHub" is visible only after GitHub is selected, which is good, but there is no equivalent discovery for WordPress site browsing or server files.
- The decision panel shows one detected URL, but not how many links or pages are affected. Users need impact before choosing.
- Dry run is a checkbox plus a button label. It should be promoted as the recommended first run, with the later real import clearly tied to dry-run results.

## Cognitive Load

- There are too many simultaneous concepts on first view: source type, universal URL input, upload, link rewrite strategy, dry run, drafts, current progress, task stages, pending decision, recent activity.
- The right panel should not be present during initial setup unless it is a contextual preview of the current setup choices.
- If a right rail remains, make it a plain "Import summary" during setup, then transform it into "Progress" after start.
- The numbered step circles create structure, but the actual action density makes the flow feel longer than necessary.

## Mobile Risks

- At mobile width, the sidebar stacks before the main content, likely pushing the actual form far down the page.
- Source cards, option cards, and progress stages will become a long single-column page. The primary action may be several screens away.
- The stage rows use three columns with status text; long labels and statuses may wrap awkwardly.
- The long GitHub URL in the input and the detected URL checkbox are likely to overflow or become hard to scan.
- Recommendation: on mobile, collapse navigation, keep the primary action sticky, make the current decision appear before the full stage list, and truncate long URLs with accessible full text.

## Accessibility Risks

- Source option buttons communicate selected state visually but do not expose `aria-pressed` or a radio-group pattern.
- The link behavior cards are labels without actual radio inputs, so keyboard and assistive tech behavior is incomplete.
- The progress meter has an `aria-label` but no value semantics. Use a native `progress` element or `role="progressbar"` with value attributes.
- The checkmark and exclamation marks in stage dots are visual state indicators. Ensure state is also conveyed in text, not only symbol/color.
- The yellow decision panel and selected cards rely heavily on background color and border color. Verify contrast in all states.
- The sidebar current nav item relies on color and inset border. Add `aria-current="page"` or equivalent.
- Long URLs need readable wrapping and accessible names; checkbox labels should make the affected count clear.
- Focus states exist for inputs and buttons, but card-like labels without controls will not receive useful focus behavior.

## Prioritized Checklist For Designer/Supervisor

1. Separate setup, scanning, decision, and result into explicit states instead of showing setup and live progress together.
2. Move the primary action above the fold or into a sticky setup footer.
3. Replace prototype-rationale copy with user task copy.
4. Specialize the source input after source type selection; keep universal paste as secondary guidance.
5. Move detailed link rewriting choices after scan results, when affected domains and counts are known.
6. Add a preflight summary before starting: source, scope, destination, mode, and safety settings.
7. Add scan-result states: no issues, warnings, required decisions, and blocking errors.
8. Add dry-run result state with clear next action to run the real import.
9. Rename ambiguous actions, especially "Keep external" and "URL treatment".
10. Convert card selections to accessible radio/pressed-button patterns.
11. Give progress semantic values and make paused/failed/resumable states visible.
12. Design the mobile order first: source form, summary/action, then optional progress/history.

