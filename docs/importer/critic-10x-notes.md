# Critic Notes: 10x Clarity Pass

Artifact reviewed: `docs/importer/user-journey-exploration.html`  
New artifact checked: `docs/importer/user-journey-10x-clarity.html` does not exist yet.

## 1. Core Clarity Principle

The next PR should make the importer feel like one obvious guided path: choose a source, preview safely, resolve only evidence-backed decisions, then run the real import.

Every screen state should answer three questions without interpretation:
- Where am I in the import?
- What does WordPress need from me now?
- What will happen if I press the primary action?

The design can keep advanced source types, safety controls, decisions, progress, and reports, but those features should be arranged around the user's current job instead of shown as a catalog of capabilities.

## 2. What PR #27 Still Made Harder Than Necessary

PR #27 improved the previous journey, but it still asks users and reviewers to mentally assemble a flow from multiple static panels. Setup, after-scan decisioning, and dry-run results are visually separated, yet they still live on one long page and use labels such as "Second state example" and "Third state example." The next artifact should feel like stateful product UI, not an annotated storyboard.

The first screen still carries too much explanation before the user reaches certainty. There is a sidebar, page header, recommendation band, selected-source summary, source input, broad paste help, quick-start box, full alternate-source chooser, optional upload, safety defaults, and setup rail. The core action is present, but the user must scan many competing blocks to understand that the minimum task is simply "paste or pick a source, then start a dry run."

Source selection is still duplicated. "GitHub repository" appears as the selected source, as the specialized input label, and again inside "Other source types." That preserves coverage, but it makes the chooser feel like implementation scaffolding rather than a resolved interaction.

The safety defaults are sensible, but the hierarchy is muddy. "Dry run first" is both a recommendation and a checkbox, which can make the safest path look optional or accidentally disableable. The next exploration should distinguish fixed first-run safety from editable advanced settings.

The dry-run result is useful but still too summary-level. It says what would create/update/skip/warn, but it does not yet show a clear review model for inspecting affected content, understanding duplicate matches, changing unresolved decisions, or seeing whether the real import publishes or saves drafts.

## 3. Mobile-First Risks To Eliminate

The mobile first viewport must get to the actual task faster. Avoid spending the top of the phone screen on navigation, explanatory copy, repeated selected-source cards, or all source types before the user can provide the source.

Primary actions must stay close to the data they act on. On mobile, "Start dry run" should be immediately reachable after the required source input, with safety status visible nearby. Do not push the action below optional upload, alternate-source browsing, or secondary settings.

Blocking decisions after scan must appear before long progress/history content. If a scan needs input, the decision and its consequence should be the dominant mobile state, with progress and activity collapsed or placed after the decision.

Long values must be treated as normal data, not edge cases. Repository URLs, server paths, feed URLs, affected domains, filenames, and localized labels need wrapping, truncation strategy, and stable row/card dimensions.

Avoid sticky or side-rail assumptions on narrow screens. Anything critical in the setup summary rail must either move inline near the source/action or become a compact step/status header.

## 4. Feature Coverage That Must Not Be Lost

The next artifact must continue to cover:
- Source types: GitHub repository/folder/file, WordPress site, feed or OPML, server path, browser upload, archive or document.
- Supported inputs: Markdown, WXR, HTML, PDF, EPUB, OPML, XML, SQL, text files, ZIP/archive paths, folders, URLs, and linked assets.
- First-run safety: dry run before writing, no content written during preview, clear handoff to real import.
- Output choices: draft creation versus publishing behavior must remain explicit.
- Scan evidence: pages/items found, media, warnings, duplicate matches, redirects or old-site links, affected counts, and examples.
- Decision handling: pause for old-site links, keep external links, rewrite selected URLs, view affected content, and report what each decision changes.
- Progress and history: source read, content preparation, URL handling, media import, write/report stage, and activity log.
- Dry-run result: would create, would update, skipped, warnings, unresolved issues, report download, and real import action.

## 5. Accessibility And Semantics Expectations

Use real semantic controls for product meaning. The source chooser is an exclusive choice and should be represented as a radio group, tabs, segmented control with correct roles, or another pattern that announces one selected value. Avoid `aria-pressed` toggle buttons for mutually exclusive source selection.

The artifact should use headings, landmarks, lists, labels, and form grouping to match the journey structure. Each state needs a clear heading and accessible name. Static chips should not look like disabled navigation unless they are actual navigation.

Every input needs an explicit label and useful help text relationship. The visible upload affordance should map to an actual file input pattern in the intended implementation, including keyboard access, accepted file messaging, and error states.

Progress should use semantic progress or status markup, with the intended implementation calling out live-region behavior for state changes and pauses requiring user input.

Status must not be color-only. Selected, ready, warning, skipped, complete, needs input, and unsafe-to-run states need text that remains meaningful without color or icon shape.

Actions need consequence-first labels or supporting text. "Run real import" must explain whether it creates drafts, publishes, updates matched content, skips unresolved items, or requires remaining decisions first.

## 6. Pass-1 Critique Of The New Artifact

`docs/importer/user-journey-10x-clarity.html` was not present when inspected, so there is no concrete pass-1 critique yet.

When it exists, the first critique should check whether it truly reduces the first screen to the minimum decision path; whether setup, scan decision, and result are represented as distinct product states rather than one long explanatory page; whether mobile reaches source input and dry-run action immediately; and whether every feature listed above remains discoverable without competing with the current task.

## 7. Pass 2 Critique

The new artifact is genuinely clearer than PR #27 in the main desktop setup path. It now reads like a product flow instead of a capability catalog: the first state is "Add source," the source type is one grouped choice, the source input is directly followed by "Start dry run," and the later states make the sequence of scan decision, dry-run result, and real import explicit. The current setup panel also does useful work by saying what will happen next and repeating that the dry run writes no content.

It is not yet defensible as "10x clearer." The first screen still carries too many simultaneous concepts for a first-time user: source type selection, URL/path input, browse, browser upload, broad accepted-format help, save-for-later, four safety defaults, expandable source support, and a setup summary all appear before the user has seen any evidence. The hierarchy is better, but the page still asks the user to understand the whole importer before they complete the one required task. The "Dry run first" checkbox also still weakens the safety promise by presenting the safest first-run mode as an editable preference.

The mobile screenshot blocks the 10x claim most strongly. The top navigation creates an obvious horizontal scrollbar inside the dark header, and the page also shows the browser/page scrollbar at the right edge; the first impression is cramped and mechanically broken. The nav steals the first row from the task, truncates "Dry-run result," and makes the user manage chrome before importing. The first-screen hierarchy is improved because the source input and "Start dry run" are visible in the first mobile viewport, but they arrive after title text, a setup badge, a six-option source grid, and a split input/browse/upload decision. On mobile, the safest version should make the required source field and dry-run action feel like the center of the screen, with source type and upload as compact secondary choices.

Feature coverage is broad but still at risk in two directions. The collapsed "What each source supports" section keeps coverage from overwhelming setup, which is good, but it hides important capability proof from reviewers unless they expand it. The later states cover old-site URL decisions, duplicates, warnings, dry-run counts, report download, and the real import handoff, but the result still does not show enough inspection depth: no item table, no per-item create/update/skip evidence, no duplicate resolution path beyond reporting, no media replacement workflow, and no explicit publish-vs-draft control at the final decision point. "Skip 1 untitled document and leave 2 media warnings unresolved" next to "Run real import" is honest, but the artifact does not show whether that is allowed, discouraged, or blocked.

The static HTML is moving in the right semantic direction, especially with native radio inputs for source type and URL handling, explicit labels on the text and file inputs, a real `progress` element, landmarks, and section headings. Remaining accessibility issues are visible in the markup. The source type group uses both a `fieldset`/`legend` and an extra `role="radiogroup"`/`aria-label`, which may produce redundant or conflicting announcements. The helper text is visually associated with inputs but not programmatically connected with `aria-describedby`. The action group uses `aria-label="Primary actions"` on a generic `div`, which gives little semantic value. Status badges rely on generated dot decoration and color emphasis; the text helps, but the visual state system still needs a non-color treatment for selected/current/warning/complete states. The stage list hides the check and warning symbols from assistive tech, so the adjacent status text must carry the full state. The result count cards are generic `div`s under an `aria-label`; a list or table-like structure would expose the four counts more reliably.

Next concrete edits for the designer:

- Fix mobile header behavior first: remove the nested horizontal scrollbar, convert journey navigation into a compact step indicator or menu, and keep "Universal Importer" plus current step without pushing the task down.
- Rework the mobile first viewport around one required source field and one primary action. Put source type behind a compact segmented/radio control, and move browser upload into a secondary affordance unless that source type is selected.
- Convert "Dry run first" from a checkbox into a fixed first-run guarantee near the primary action. Leave editable safety settings for drafts, URL rewriting, and duplicate reporting below the main action or in an advanced section.
- Add a richer dry-run result inspection area: affected item rows, create/update/skip reason, duplicate match evidence, warning ownership, and a clear route to fix unresolved media/title issues.
- Make the final real-import action conditional and consequence-first: show whether unresolved warnings are allowed, which items will be skipped, whether content remains draft or publishes, and what must be resolved before the green action enables.
- Tighten static semantics before the next screenshot pass: connect help text with `aria-describedby`, avoid redundant radio-group labeling, expose result counts as a list/table, and ensure status text, not only color or icon shape, communicates every state.

## 8. Pass 3 Critique

Pass 2 appears to have solved the largest mobile/header/task-entry blockers. The mobile header no longer shows the broken horizontal scrolling state from the earlier screenshot, the navigation has compressed into four short step labels, and the first mobile viewport now leads with "Add source," the required source field, and a full-width "Start dry run" action before the source-type grid. That is a meaningful improvement: the task is no longer buried under the source chooser or optional upload controls. The desktop path is also calmer, with the side summary giving useful "what happens next" context without taking over the primary task.

This is close to a strong draft, but not quite there. The remaining blocker is not layout polish; it is the safety and evidence model. "Dry run first" is still a checked checkbox under "Safety defaults," which makes the strongest safety promise look like a preference the user can turn off before the first scan. The copy elsewhere says no content is written during dry run, but the control model undercuts that guarantee. For a PR whose central argument is importer clarity, first-run dry-run safety should be presented as the fixed mode of this journey, with configurable settings separated into real-import defaults such as drafts, URL rewriting, and duplicate reporting.

The dry-run result is understandable at a summary level, but it still lacks the detail evidence needed to convince reviewers that the importer can support confident decisions. The result counts and highlight bullets say what would happen, and the duplicate/warning report gives a few examples. What is missing is the review surface between summary and final import: item-level rows for would-create, would-update, skipped, and warning cases; the specific matching evidence behind duplicate updates; per-item paths/titles/statuses; ownership of warnings; and a route to resolve or intentionally accept each unresolved issue. The artifact says "2 media references need replacement files," but it does not show how the user finds those references, supplies replacements, or decides to proceed without them.

The final real-import state is clearer than before because it repeats the consequences before writing. It still leaves a product policy ambiguity: the green "Run real import" button is enabled while the summary says the import will "skip 1 untitled document and leave 2 media warnings unresolved." That may be acceptable, but the UI must say whether unresolved warnings are allowed, discouraged, or blocking. If they are allowed, the action should make that explicit. If they are blocking, the green action should not appear enabled until the user resolves or accepts them.

Static HTML and accessibility are improved but still worth tightening before PR. The artifact uses native radio inputs, labels, headings, landmarks, a real file input, and a `progress` element, which is good for a static exploration. Remaining issues: helper text is not connected to inputs with `aria-describedby`; the source type control has both `fieldset`/`legend` and a redundant `role="radiogroup"`/`aria-label`; the result counts are generic `div`s instead of a list or table-like structure; the "Primary actions" label sits on a generic `div` without much semantic value; and several status treatments still rely heavily on color, icon shape, or generated dots even though adjacent text often helps. These are not conceptual blockers, but they are small fixes that would strengthen the PR.

Recommendation: do one more targeted pass before opening the draft PR. Keep the now-improved mobile/header structure, but focus only on three changes: make dry run a fixed first-run guarantee instead of a checkbox, add item-level dry-run evidence with duplicate and warning details, and make the real-import action conditional or consequence-explicit for unresolved issues. After that pass, this should be ready to open as a strong draft PR.

## 9. Pass 4 Critique

PR #28 is now strong enough as a draft exploration. It does not need to be production-complete to justify discussion: the artifact shows a coherent importer journey, keeps setup focused on one source and one dry-run action, separates scan decision, dry-run result, and real-import readiness into distinct states, and documents the exploration through designer and critic notes. The draft PR body is also honest about scope: no runtime code is touched, and the HTML is a static product-direction artifact. That is the right bar for a draft exploration.

The highest-priority issue is now mobile result/detail readability, not the setup screen. The first mobile viewport is acceptable: "Add source," the required field, the primary action, the dry-run guarantee, upload fallback, and source type are all reachable in a sensible order. The tall screenshot shows the next weak point more clearly: the dry-run result table is dense and depends on horizontal scrolling, small text, and compact action links. That may be tolerable for desktop review, but it is not a convincing mobile model for inspecting create/update/skip/warning evidence, duplicate confidence, owners, and fixes. A mobile result state probably needs stacked outcome rows, expandable evidence, and actions that stay close to each item.

Remaining feature coverage risks are mostly about depth rather than breadth. Source types, supported formats, dry-run safety, URL decisions, duplicate reporting, warnings, result counts, report download, and the final real-import handoff are all represented. What is still thin is the real resolution workflow: replacing missing media, confirming or editing skipped untitled content, changing duplicate-match decisions, switching publish-vs-draft behavior at the final step, and seeing post-import links or error recovery after the real import runs. The current artifact proves the journey shape, but not every important branch inside the result review and remediation phase.

Implementation and accessibility caveats remain. The static controls do not express actual state transitions, validation, disabled states, async loading, failure states, or live updates. The result table is semantically better than prior result cards, but its responsive behavior is weak and would need a mobile-specific structure in production. Status is improved because adjacent text carries most meanings, but badges, generated dots, and color-coded pills still need audit against non-color and high-contrast use. The page uses useful native labels, `aria-describedby`, landmarks, progress, lists, and a table, but the intended implementation would still need keyboard flow, focus management after state changes, live-region behavior for scan progress and blocking decisions, real file-input error handling, and accessible disclosure/table behavior.

Recommendation: pause this PR for human feedback rather than keep iterating immediately. The draft has crossed the threshold from "needs another pass before review" to "clear enough to ask whether this is the right product direction." Further iteration should be guided by feedback on the result-review model, especially whether mobile users should inspect detailed outcomes in a table, stacked cards, filters, or a dedicated detail view.

## 10. Pass 5 Critique

A compact resolution example would improve clarity if it stays deliberately small. The current artifact shows that the dry run can identify warnings and assign next actions, but it still asks reviewers to infer what happens after pressing one of those action links. One inline example would close that loop and prove the intended interaction model: open an issue, see the evidence, choose a resolution, and return to the result with the final import consequence updated. More than one example would create bloat for this draft PR because the page is already carrying setup, scan decision, result review, and real-import readiness.

The best single issue to demonstrate is missing media. It is concrete, visual, and user-actionable: the importer can show the broken reference, the affected page, the expected file name, and a "replace file or keep warning" choice. It also tests the strongest unresolved branch in the current design without reopening the whole import policy. Untitled appendix is also clear, but it is mostly a text-field fix. Duplicate match is important, but it can drag the artifact into matching rules and confidence thresholds. URL decision is already represented well enough in the scan-decision state, so it would be redundant as the one resolution example.

Keep production behavior out of scope for this draft PR. Do not specify full media-library upload mechanics, conflict resolution rules, duplicate-match algorithms, bulk editing, post-import recovery, validation timing, async failure states, or the final mobile implementation pattern. Those belong in follow-up product and implementation work after reviewers agree that the journey shape is right. This PR should remain a static direction artifact, not a disguised functional spec.

Recommendation for the next designer move: add one compact missing-media resolution detail directly under the dry-run result row or as a small expanded row in the result area, then stop. The example should show the affected content, the missing source path, the replacement choice, and the changed consequence for "Run real import." If that addition makes the mobile screenshot feel heavier, prefer an expandable stacked row over adding another full state.

## 11. Pass 6 Critique

The tiny post-import outcome is useful as a boundary marker, but only if it remains tiny. The current draft already does enough work to prove the core journey: source, dry run, evidence, selected issue resolution, and consequence-explicit real import. Adding a full completed-import state would bloat the artifact because it would introduce a new product surface with its own questions: success summaries, partial failures, retry behavior, created-content navigation, logs, rollback expectations, report persistence, and error recovery. That is valuable work, but it belongs after reviewers agree on the pre-import decision model.

The minimum post-import feedback that belongs in this draft is one sentence or bullet near the real-import boundary: after running, each created or updated draft links to its WordPress edit screen, and the final report keeps skipped items and unresolved warnings. That is enough to answer "where do I land after the write?" without making this PR specify the completion experience. The existing "After running, each created draft links to its WordPress edit screen" bullet is directionally right, but it should also mention updated drafts and the final report if the designer chooses to tighten the copy later.

Everything else should remain explicitly out of scope: a fifth completed-import state, post-import dashboard, retry queue, rollback model, automatic publish flow, detailed success table, recovery from failed media writes, notification behavior, audit-log retention, and exact report schema. Those would pull the artifact away from its strongest argument, which is the safe pre-write importer journey.

Recommendation: do not edit the HTML for this pass. The designer should document the boundary in notes or PR description rather than add more UI. If a later reviewer asks for post-import clarity, the smallest acceptable HTML edit would be copy-only in the existing "Before running" panel, not a new state or expanded outcome surface.

## 12. Pass 7 Critique

Demonstrating source-type adaptation would improve clarity only if it proves that the same journey responds to different source evidence, not if it reopens the old source catalog. The current artifact already carries broad source coverage in the setup disclosure and then follows one GitHub-folder example through dry run, resolution, and real-import readiness. That is the right primary shape for PR #28. Adding separate examples for WordPress sites, feeds, uploads, archives, and server paths would make the page feel like a capability matrix again and would weaken the action-first argument.

The smallest useful proof is a notes-only micro-example for one or two alternate sources. For example: "WordPress site" could adapt the source label to "Site URL," preview "12 posts, 38 media items, 4 authors, 2 old domains," and show duplicate evidence as matching slugs in the destination. "Feed or OPML" could adapt the label to "Feed or OPML URL," preview "24 feed items, 3 skipped without full content," and frame warnings around linked media or truncated entries. Those examples are enough to prove that labels, scan evidence, and warning ownership change by source type while the journey remains the same.

This should stay out of the UI for this draft. A visible source-specific UI branch would require either additional states, tabs, screenshots, or conditional examples, and each option adds review weight without answering the main open question: whether the importer journey is clear when one representative source is followed end to end. The HTML should not be edited unless reviewers specifically cannot believe the source-type selector changes the labels and evidence. If that happens, the smallest UI edit would be a single helper sentence under the source field, not another result section.

Recommendation for the designer: keep the HTML focused on the GitHub-folder path and add source adaptation only to notes or the PR description. Phrase it as an implementation principle: source type changes the input label, accepted examples, scan evidence, and warning copy; it does not change the four-step journey. If a later pass needs visual proof, add one compact alternate-source annotation near the setup field and stop before adding a catalog.

## 13. Pass 8 Critique

The current artifact is in good shape for a draft PR. The most important earlier issues have been addressed: dry run is now a fixed first-run guarantee, the result state includes item-level evidence, the missing-media example closes one resolution loop, and the final import action says why it is allowed with warnings. Pass 8 should not reopen the journey structure. The useful review now is about accessibility risk, small static markup fixes, and what to defer to production design.

Accessibility and non-color-state risk is reduced but not gone. Most states have text labels, which is good: "Needs decision," "Dry run complete," "Allowed with warnings," "Done," "Needs input," and the outcome pills all carry words. Remaining risk comes from the visual system still leaning on color, generated dots, inset bars, and pill color to make state feel different. The source selector has a real checked radio, but visually "GitHub" is selected mostly through blue fill and underline. The current progress row is amber, the completed rows are green dots, and the import caution panel is amber. Those are acceptable for a static exploration, but the production UI should make selected/current/warning/done states survive without color through persistent text, position, control state, and accessible names.

There are a few low-risk static markup fixes worth doing before review if the designer wants one small cleanup pass. The mobile result table visually rebuilds row labels with CSS `content: attr(data-label)` after hiding the table head; that is useful visually, but generated labels are not a reliable accessibility surface, and block-displayed table parts can be uneven across assistive tech. For a static artifact, either keep the table semantics simpler on mobile or add visible inline labels in the cells. The source type radios would be easier to review if each input had an explicit `value`, and the selected label could include non-color copy such as "GitHub, selected" only if it does not make the UI clumsy. The icon-like badge dots and stage dots are decorative, so the adjacent text should remain the full state source; do not rely on the dot or checkmark to carry meaning. The generic "Browse" button and row actions like "Review match" and "Replace media" are understandable in context, but clearer accessible names would be a cheap improvement in production-facing markup.

Several concerns should remain out of scope for this draft PR. Do not turn the resolution example into a real media upload flow, validation model, upload progress state, replacement conflict workflow, duplicate-match override system, report schema, retry queue, rollback design, focus-management spec, live-region implementation, or responsive table/card framework. Those are production concerns. The current HTML only needs to show the product direction and the safety/evidence model clearly enough for review.

Recommendation for the designer: stop after at most one small accessibility polish pass. If editing, focus on non-color state clarity and mobile result semantics, not new states or broader source examples. In the PR description, explicitly call out that production implementation still needs keyboard flow, focus management after state changes, live progress/status announcements, real file validation, and tested responsive result review. That separation will keep the draft review centered on whether the importer journey is right.

## Pass 9 Critique

The PR description is now stale in emphasis, not in intent. It still says the artifact separates setup, scan decisions, dry-run result, and real-import readiness, but it does not tell reviewers that the artifact has since grown into a more specific safety/evidence proposal: first run is a fixed dry run, result review includes item-level evidence, one missing-media warning has an inline resolution example, the real-import action is explicitly allowed with non-blocking warnings, and the tiny post-import block shows edit/report links without designing a full completion flow.

The PR body should add those points so reviewers judge the current artifact rather than an earlier setup-focused pass. It should also make the caveats prominent: this is static HTML only; source-type selection does not actually adapt labels or scan evidence yet; counts, warnings, duplicate evidence, and report links are illustrative; production still needs computed warning severity, real validation, keyboard and focus behavior, live progress/status announcements, file-upload error handling, high-contrast QA, and larger-result responsive testing.

Recommendation: no more artifact changes are needed before review unless the author wants a tiny copy-only polish. The main blocker is a better PR description that frames the artifact as a draft product-direction artifact with known implementation and accessibility gaps, not as a complete importer UI or functional spec.

## Pass 10 Critique

The artifact is now lean enough for a draft PR. It is not small in raw HTML size, but its product surface has stopped expanding: one source setup, one scan decision, one dry-run result with item-level evidence, one compact missing-media resolution example, and one real-import readiness boundary. That is the right amount of proof for a static product-direction artifact because it demonstrates the safety/evidence model without pretending to implement the whole importer.

The top remaining bloat risks are:

- Turning the supported-source disclosure into visible source-specific journeys. The artifact already proves the GitHub-folder path and states that other source types adapt labels, evidence, and warnings. More examples would pull the page back toward a capability catalog.
- Expanding the missing-media resolution example into a full remediation system. The current example is useful because it shows one issue, evidence, a replacement choice, and the changed consequence. Adding upload progress, validation, conflicts, bulk repair, or retry handling would cross into production design.
- Adding a completed-import state. The tiny post-import note is enough for this draft. A success dashboard, retry queue, rollback story, detailed report schema, or created-content navigation table would create a fifth product surface and distract from the pre-write decision journey.

Do not remove the pieces that protect feature coverage: the source-type selector and supported-source disclosure, the broad accepted-format help, the fixed dry-run guarantee, URL decision handling, item-level result evidence, duplicate and warning rows, the missing-media resolution example, the "allowed with warnings" import consequence, draft-vs-publish wording, report download, and the small post-import boundary note. Those elements are what keep the artifact from becoming a pretty but under-specified setup screen.

Recommendation: keep the artifact and only document boundaries. The next useful change is not another UI simplification pass; it is PR-description clarity that says this is a static draft, source adaptation is illustrative, counts and evidence are sample data, and production still needs validation, accessibility behavior, responsive result testing, and real importer state transitions.

## Pass 11 Critique

The latest bloat trim did not remove anything reviewers need. The deleted "First screen," "State 2," "State 3," and "State 4" labels were artifact scaffolding rather than product evidence, and the shorter state descriptions make the journey read more like UI. Removing "Save setup for later" is also the right tradeoff for this draft because it was a secondary workflow that did not help prove the source-to-dry-run-to-import path. Moving the post-import reminder into the tiny "After running" outcome keeps the boundary visible without adding another completion surface.

The shorter source help still preserves feature coverage because the detailed coverage remains in the disclosure. The inline help now says the field accepts URLs, repositories, feeds, server paths, uploads, exports, and documents, which is broad enough for the first screen. The "What each source supports" disclosure still names GitHub repositories, branches, folders, files, releases, Markdown docs, linked assets, WordPress posts/pages/media/authors/comments/REST/WXR, RSS/Atom/JSON Feed/podcast/OPML, mounted folders and staging paths, browser-selected files, and ZIP/WXR/Markdown/HTML/PDF/EPUB/XML/SQL/OPML/plain text. That is enough for reviewers to verify coverage without forcing every format into the primary field hint.

The one small risk is that "exports" in the inline help is less concrete than "WXR" for WordPress reviewers, but the disclosure covers it immediately below. I would not restore the long accepted-format sentence; it made the first task harder to scan and duplicated the source-support panel. If any copy changes are made, keep them copy-only and tiny: consider "uploads, exports, and document files" or "WordPress exports and documents" only if reviewers miss WXR at first glance.

Recommendation: keep the HTML as-is. Do not restore the removed labels, the saved-setup action, or the long source-help list. The only optional final adjustment is PR-description or notes copy that explicitly says the collapsed source disclosure carries the full format coverage while the primary field help stays intentionally short.

## Pass 12 Critique

The shortened source help is generic, but not too generic for this draft because the nearby disclosure carries the WordPress/WXR coverage. The primary field help should stay short so the first task remains "paste a source and preview it"; forcing WXR, REST, feeds, archives, and document formats back into that line would make the setup feel like a format catalog again.

If the author wants one copy-only polish, the smallest useful wording is to replace "uploads, exports, and documents" with "uploads, WordPress exports, and documents." That adds a WordPress-specific cue without restoring the long accepted-format list or editing the HTML structure.

Recommendation: keep as-is unless a reviewer specifically says WXR is not discoverable. If changing before review, edit only that one phrase; otherwise leave it to reviewer feedback.

## Pass 13 Critique

The fresh mobile first viewport still proves the simplest path. The screen opens with "Import content," "Add source," one labeled source field, the pasted GitHub path, a full-width "Start dry run" action, and the fixed green dry-run guarantee before the secondary upload and source-type choices. That is the right priority after the bloat trim: paste or pick a source, preview safely, then review evidence before the real import. The header and four-step nav consume some vertical space, but they no longer break the first task or turn the top of the phone into a capability catalog.

Desktop also holds together as one journey. The page reads down the expected path: setup, scan decision, dry-run result, and real-import readiness. The source-support inventory is collapsed, the setup sidebar is contextual rather than dominant, and the result area shows enough item-level evidence and one compact resolution example without adding more states. The whole tall screenshot is long, but it feels like a guided review surface rather than a matrix of everything the importer could ever do.

No issue is severe enough to justify a tiny HTML edit before review. The only visible nits are review-level tradeoffs: the mobile first viewport ends just as editable settings begin, the source type grid is still visible below the main action, and the desktop result table is dense. None of those regress the core story after the trim, and touching the HTML now would risk reopening scope.

Deferred production risks should be named only as framing: this is static sample data; alternate source types do not actually adapt labels or evidence here; production still needs real validation, keyboard/focus behavior, live progress announcements, high-contrast QA, and responsive testing for larger result sets. Those caveats support review context, not more UI in this PR.

Recommendation: keep `docs/importer/user-journey-10x-clarity.html` stable for review and use the PR description to frame the remaining production risks.

## Pass 14 Critique

The current artifact preserves the full feature surface without letting the first task become a source catalog again. The setup path stays lean: choose or paste one source, start a guaranteed dry run, then inspect evidence before the real import. Coverage is still present through the source selector, the collapsed "What each source supports" disclosure, editable draft/URL/duplicate settings, the scan decision, item-level result evidence, the missing-media resolution example, report download, and the real-import consequence summary.

The collapsed support disclosure is enough for review. It names the important source families and formats, including WordPress REST/WXR, feeds/OPML, archives, documents, SQL, XML, Markdown, HTML, PDF, EPUB, server paths, browser uploads, folders, URLs, and linked assets. The only tiny wording improvement remains optional: "uploads, WordPress exports, and documents" would make the primary helper line more WordPress-specific, but the current "uploads, exports, and documents" is not misleading because WXR is visible in the disclosure immediately below. I would not edit the HTML for that unless a reviewer says WordPress exports are hard to find.

Source adaptation and alternate-source examples are correctly deferred. The PR proves one representative GitHub-folder journey end to end, and that is the right product argument for this draft. Adding WordPress-site or feed examples in the UI would undercut the bloat trim by turning the artifact back into multiple source journeys. The honest review framing is that source type will adapt labels, accepted examples, scan evidence, and warning copy in production, while this static HTML uses one sample path to prove the shared four-step journey.

No missing coverage justifies an HTML edit before review. The artifact is not under-proving the importer surface; the remaining caveats are implementation caveats: static sample data, no live source-specific adaptation, no real validation, no keyboard/focus or live-region behavior, and no large-result responsive testing. Those should stay short in the PR framing and should not become more UI in this design-only PR.

Recommendation: keep `docs/importer/user-journey-10x-clarity.html` stable. Do not add a visible matrix, alternate source journey, extra state, or pre-review copy tweak unless reviewer feedback identifies a concrete misunderstanding.

## Pass 15 Critique

The opened source-support disclosure provides enough feature proof without becoming a capability matrix. It stays below the required source field, dry-run action, guarantee, and editable settings, so the primary setup task still leads. The six compact cards are broad enough to reassure reviewers that the importer covers GitHub, WordPress sites, feeds, server paths, uploads, archives, documents, WXR, OPML, linked assets, and common file formats, but they do not introduce separate journeys, states, examples, or implementation rules.

The added "ZIP/archive paths" and "plain text files" wording improves coverage with minimal cost. "ZIP/archive paths" closes the gap between local/server paths and archive inputs, and "plain text files" makes the document bucket feel less artificially narrow. Neither phrase adds meaningful bloat because both appear inside already-scannable cards, not in the primary helper line or the first action path.

Recommendation: keep the HTML stable. The expanded disclosure is doing the right job: feature proof on demand, not a visible source matrix. A disclosure-only copy or layout edit is not justified unless a reviewer still misses archive paths or plain text support after opening it.
