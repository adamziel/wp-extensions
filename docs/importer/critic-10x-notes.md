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

## Pass 16 Critique

This is now clear enough for human product/design review. The artifact shows a complete, reviewable direction: add one source, run a guaranteed dry run, resolve the one blocking scan decision, inspect evidence, and run the real import only after the consequences are explicit. The mobile and desktop screenshots both support that story, including the opened source-support disclosure, without making reviewers assemble the journey from internal annotations.

Visible copy no longer feels like scaffolding. The removed state labels and saved-setup affordance were the main artifact-like pieces; what remains reads like product UI. The source-support cards, dry-run guarantee, result evidence, warning resolution example, and "allowed with warnings" handoff are explanatory, but they explain user consequences rather than internal implementation mechanics.

Caveats are properly contained outside the UI. Static sample data, non-functional source adaptation, validation, accessibility behavior, live progress announcements, high-contrast QA, and large-result responsive testing should stay in notes or the PR body. Adding those caveats to the visible artifact would make the UI over-explain itself and weaken the current handoff.

Recommendation: keep `docs/importer/user-journey-10x-clarity.html` stable for review. The only tiny copy edit previously identified, changing "uploads, exports, and documents" to mention WordPress exports, is not justified now because the opened disclosure already exposes WXR and WordPress REST coverage. Do not edit the artifact unless a human reviewer identifies a concrete misunderstanding in the visible UI.

## Pass 17 Critique

The mobile edge-case evidence does not show a concrete layout blocker. The Pass 17 bitmaps make the right edge look clipped at the 420px capture, especially around the header nav and long source value, but the DOM overflow check reports `client=485 scroll=485 bad=[]`. That means the committed CSS is not producing document-level horizontal overflow in the measured viewport; the 420px image is more likely a headless capture scaling artifact than proof that the page is wider than the viewport.

The existing 500px mobile screenshot should remain the authoritative mobile evidence for this PR. It shows the intended mobile hierarchy clearly: title, "Add source," source field, "Start dry run," fixed dry-run guarantee, upload fallback, source type, editable settings, and source-support disclosure. The Pass 17 images are still useful as stress evidence for narrow capture and larger text, but they should not override the cleaner mobile screenshot unless a reproducible DOM measurement or browser inspection identifies a specific overflowing element.

Recommendation: keep `docs/importer/user-journey-10x-clarity.html` stable. A tiny CSS edit is not justified without a concrete blocker because it would churn the design artifact to fix a screenshot artifact. If this reappears in manual browser testing, the right fix would be a narrowly targeted nav/value wrapping adjustment, not a new state, control, source example, or spec expansion.

## Pass 18 Critique

As an implementation handoff and reviewer-facing draft, the artifact should remain stable. It now proves the intended journey without adding forbidden scope: one source setup, a guaranteed dry run, one blocking URL decision, item-level dry-run evidence, one compact warning-resolution example, and an explicit real-import gate that creates drafts, skips unresolved items, and carries non-blocking warnings into the report. There are no plugin/runtime implications in the HTML, and it does not introduce a capability matrix or another source-specific journey.

The one remaining vague spot is the setup helper phrase "uploads, exports, and documents." On its own, "exports" is less WordPress-specific than "WXR exports," so a reviewer skimming only the first setup field could briefly wonder whether WordPress export support is represented. The opened "What each source supports" disclosure resolves that ambiguity by naming WordPress REST/WXR and the archive/document formats, so changing the primary helper would be optional copy polish rather than a justified design fix.

Recommendation: do not edit `docs/importer/user-journey-10x-clarity.html` in this pass. A tiny HTML edit would only be justified if a human reviewer specifically misses WordPress export/WXR support; in that case, change only the helper phrase to "uploads, WordPress exports, and documents." Do not add more setup copy, alternate source examples, validation behavior, upload flows, completion states, or implementation caveats to the visible UI.

## Pass 19 Critique

The tiny copy edit is now justified. The Pass 19 mobile first-screen screenshot shows the helper phrase "uploads, exports, and documents" plainly in the setup panel while the source-support disclosure that names WordPress REST/WXR is not visible yet. Combined with the repeated reviewer-handoff concern from Passes 12, 14, 16, and 18, "exports" is doing too little work for a WordPress importer in the exact place reviewers will skim first.

The only justified edit is replacing that phrase with "uploads, WordPress exports, and documents." Do not add a sentence, source examples, a visible matrix, controls, or any other explanation; the disclosure already carries the detailed coverage.

## Pass 20 Critique

The updated mobile first-screen screenshot does not show a regression from adding "WordPress" to the helper line. The phrase now wraps as "uploads, / WordPress exports, and documents" inside the same two-line help block, with no awkward orphaning, no new crowding around the source field, and no hierarchy damage to the full-width "Start dry run" action or the fixed dry-run guarantee.

The wording also does not imply that only WordPress exports are supported. The same line still leads with URLs, repositories, feeds, server paths, and uploads, and the visible source-type grid immediately reinforces GitHub, WordPress site, Feed or OPML, Server path, Browser upload, and Archive or document as parallel options. "WordPress exports" reads as one concrete supported input inside a broader importer, which is exactly the clarification the first screen needed.

Recommendation: keep `docs/importer/user-journey-10x-clarity.html` stable. No follow-up HTML edit is justified for this pass.

## Pass 21 Critique

The full desktop and mobile screenshots after the helper edit show no regression in the journey. The first screen still leads with one source field, one "Start dry run" action, and the fixed dry-run guarantee; the added "WordPress exports" wording clarifies coverage without turning setup back into a format catalog. On mobile, the helper wraps cleanly and the source type grid, editable settings, and setup summary remain secondary to the paste-preview path.

Sequence clarity holds across the whole page. Setup, scan decision, dry-run result, and real-import readiness are distinct enough to review as a single guided importer journey, and the later states still explain why the dry run pauses, what evidence was found, which warnings remain, and what the real import will actually write. The real-import gate remains consequence-first: drafts are created or updated, zero items publish, the untitled document is skipped, and one unresolved media warning carries into the report.

Source coverage is also stable. The first-screen helper now names WordPress exports while the disclosure continues to carry the broader proof for GitHub, WordPress sites and WXR, feeds and OPML, server paths, browser uploads, archives, documents, SQL, XML, Markdown, HTML, PDF, EPUB, and plain text files. That is enough for this design-only artifact; adding a visible source matrix or alternate-source journey would be bloat.

Recommendation: keep `docs/importer/user-journey-10x-clarity.html` stable. No tiny follow-up is justified in this pass. Remaining concerns are production handoff caveats only: real validation, source-specific adaptation, keyboard and focus behavior, live progress announcements, high-contrast QA, and large-result responsive testing.

## Pass 22 Critique

The opened support disclosure still feels secondary to the dry-run path after the helper edit. In the mobile screenshot, the required source field, "Start dry run" action, fixed dry-run guarantee, upload fallback, source-type choice, and editable settings all appear before the support cards. Opening the disclosure adds proof of coverage, but it does not compete with the main paste-preview action or turn the first screen into a source matrix.

The new helper wording also removes the one vague coverage cue without creating duplicate copy. "WordPress exports" appears in the short helper as a concrete example, while the disclosure gives the fuller source proof through "WordPress site" with REST/WXR and "Archive or document" with WXR, Markdown, HTML, PDF, EPUB, XML, SQL, OPML, and plain text. That repetition is useful layered disclosure rather than bloat because the helper answers "can I paste this kind of source?" and the opened cards answer "what does each source family cover?"

Source coverage is clear enough for this design-only artifact. The cards remain compact, source-family based, and scannable; they do not add alternate journeys, per-format examples, or source-specific result states. The one small wording imperfection is that WordPress exports now appear in both the helper and disclosure, but that is acceptable because the two placements serve different levels of attention.

Recommendation: keep `docs/importer/user-journey-10x-clarity.html` stable. No tiny follow-up is justified in this pass. Do not add a visible source matrix, more format examples, or another alternate-source flow unless human review identifies a specific misunderstanding.

## Pass 23 Critique

The tablet-width screenshot remains stable after the helper edit. The setup hierarchy is intact: the source field, dry-run action, guarantee, source-type selector, editable settings, and collapsed support disclosure all fit without crowding, and the support disclosure does not reintroduce source-catalog bloat.

The narrow-mobile screenshot shows one concrete breakpoint issue. The setup panel appears clipped at the right edge: the intro helper line, long source value, setting helper text, and the "Archive or document" source option lose visible content instead of wrapping or being fully contained. That is hierarchy damage at the narrowest breakpoint because it makes the first-screen setup feel mechanically cropped, even though the dry-run CTA and guarantee still remain legible.

Recommendation: do not add more source coverage, states, or explanatory UI. A tiny follow-up is justified only for narrow mobile containment: make the source-type choices and long setup helper/value text wrap or stack cleanly at the narrow breakpoint, with no copy expansion and no new journey content. Tablet can remain unchanged.

## Pass 24 Critique

The Pass 23 clipping concern is not actionable with the new DOM measurement. The narrow screenshot still looks cropped on the right edge, but the instrumented run reports `inner=500 client=485 scroll=485 body=485` while the screenshot was captured at 390px. That means Chromium laid out a 485px-wide document inside an effective 500px viewport, then the screenshot crop cut into that wider layout. It does not show committed CSS producing document-level horizontal overflow at the measured layout width.

The tablet screenshot remains stable, and the measured document width matching the scroll width clears the specific concern that the setup panel, helper text, source value, or source-type grid is forcing page-level overflow. The visible 390px crop is still useful as evidence that the capture setup can make the first screen look clipped, but it is not enough to justify changing the design artifact.

Recommendation: keep `docs/importer/user-journey-10x-clarity.html` stable. No tiny follow-up is justified unless a manual browser check or a DOM measurement at an actual 390px layout viewport reproduces `scrollWidth > clientWidth` or identifies a specific overflowing element.

## Pass 25 Critique

Reviewer readiness is strong enough without another HTML edit. The artifact does not visibly leak implementation caveats, TODO-style annotations, accessibility notes, validation warnings, or PR-body framing into the UI. What remains on screen reads as product copy: dry-run safety, source coverage, URL decisions, item-level evidence, warning ownership, and real-import consequences.

The most explanatory visible pieces are still justified. "What each source supports" is on-demand feature proof, not a spec matrix; "First run is always a dry run" and "Allowed with warnings" explain user consequences at decision points; and the result table evidence is needed for reviewer confidence. Moving any of those out of the artifact would weaken the journey rather than reduce bloat.

Recommendation: keep `docs/importer/user-journey-10x-clarity.html` stable. No tiny visible-copy trim is justified in this pass. Production caveats such as static sample data, source-specific adaptation, validation, keyboard/focus behavior, live progress announcements, contrast QA, and large-result testing should stay in notes or the PR body, not in the visible design artifact.

## Pass 26 Critique

The skipped-title evidence trim did not remove necessary evidence. In the mobile result section, the skipped item still shows the consequence path clearly enough: `Skipped`, `Untitled appendix`, `appendix/no-title.md`, `No readable title found`, `Editor`, and `Add title or skip`. That is the right level for the result review because it explains what happened and who needs to act without exposing parser mechanics or title-detection implementation details.

The surrounding copy also preserves the next action and import consequence. "Warnings still open" says the title warning can be fixed by adding a title or confirming the skip, the real-import gate says unresolved items will be skipped, and the "Before running" panel repeats that importing the appendix requires adding a title in the dry-run result. That layering is useful rather than bloated because each placement answers a different user question: item evidence, remaining issue, final write consequence, and pre-run remediation.

Recommendation: keep `docs/importer/user-journey-10x-clarity.html` stable. No tiny copy adjustment is justified for this pass.

## Pass 27 Critique

The "Ready for real import" section works as the last pre-write decision. It is consequence-explicit before the green action: running is allowed because warnings are non-blocking, content stays unpublished, 9 drafts are created, 3 drafts are updated, 0 items publish, 7 old-site URLs remain external, 1 untitled document is skipped, and 1 media warning is carried into the final report. The adjacent "Before running" panel also keeps remediation choices pre-run rather than implying the import has already succeeded.

The "After running" block is close to the boundary but still acceptable. It does not introduce a success dashboard, retry queue, rollback flow, or completed-import state; it simply confirms where the user lands after the write: edit drafts, review updates, see the skipped item, and open the final report. That helps users understand what will happen after pressing the button without bloating the pre-run state into a post-import experience.

Recommendation: keep `docs/importer/user-journey-10x-clarity.html` stable. No tiny copy or structure trim is justified in this pass. If future review says the post-run promise feels too strong, the only acceptable trim would be to shorten "After running" to a single sentence promising created/updated draft links and a final report, but that is not needed based on the current screenshot.

## Pass 28 Critique

The mobile full-height screenshot confirms that the "Ready for real import" handoff is understandable without another design edit. The amber "Allowed with warnings" badge, the heading "creates drafts, skips unresolved items," and the first gate sentence work together: users can see that running is allowed, why it is allowed, and that the remaining issues are non-blocking rather than ignored. The consequence list is also exact enough for a pre-write decision: 9 drafts created, 3 drafts updated, 0 published, 7 old-site URLs kept external, 1 untitled document skipped, and 1 media warning carried into the final report.

The mobile section is dense, but it is not overloaded because each block answers a distinct last-mile question. The primary gate answers "can I run?", the list answers "what will WordPress write?", the button row gives both the forward action and "Back to dry-run result," "After running" sets a small expectation for draft links, update review, skipped content, and the report, and "Before running" gives pre-run remediation advice without adding new controls. The tiny post-run promise remains appropriately small; it does not expand into a completed-import journey.

Recommendation: keep `docs/importer/user-journey-10x-clarity.html` stable. No tiny copy or structure trim is justified for this pass. The only future trim worth considering, if reviewers still feel the mobile handoff is heavy, would be shortening the "After running" list to one compact sentence about draft/update links and the final report; based on this screenshot, that change is optional rather than needed.

## Pass 29 Critique

The gate-copy trim does not regress clarity. "Allowed because unresolved warnings are non-blocking." remains grammatical in context: the preceding badge establishes the allowed-with-warnings state, the heading names the real import action, and the following sentence explains that content will not publish, the untitled document will be skipped, and missing media references remain reported for follow-up.

Desktop and mobile both keep the sentence readable and sufficiently explicit. On desktop, it anchors the caution panel before the consequence list and green action. On mobile, the same sentence still appears before the detailed bullets and button, so the user does not have to infer whether warnings block the import.

Recommendation: keep `docs/importer/user-journey-10x-clarity.html` stable. No copy adjustment or revert is justified for this pass.

## Pass 30 Critique

The first mobile viewport is still clear enough for source entry. It opens with "Import content," "Add source," a specific source label, the pasted GitHub path, the full-width "Start dry run" action, and the fixed dry-run guarantee before optional upload, source type, and settings. That keeps the minimum task visible: provide a source, preview safely, then review evidence before writing content.

Source-type coverage is visible without becoming a catalog. The helper line now names URLs, repositories, feeds, server paths, uploads, WordPress exports, and documents, while the two-column source-type grid exposes GitHub, WordPress site, Feed or OPML, Server path, Browser upload, and Archive or document. The collapsed "What each source supports" disclosure remains available below the settings for detailed proof, so no extra source examples or visible matrix are justified.

There is some mobile density, but not actionable bloat. The header, source field, browse/upload fallback, source grid, three editable settings, and preview summary all fit into the first capture sequence, and each block has a distinct job. Removing the source grid or settings would weaken coverage and consequence clarity more than it would improve the first-screen task.

The 430px crop should be treated like the earlier headless crop concern, not as a confirmed responsive defect. The 430px bitmap cuts the right edge of long values and controls, but the 500px capture of the same artifact shows normal containment and the same hierarchy without a broken layout. Without a DOM measurement or manual browser check showing real `scrollWidth > clientWidth` at an actual narrow viewport, a CSS change would be churn against the static artifact rather than a fix.

Recommendation: keep `docs/importer/user-journey-10x-clarity.html` stable. No tiny CSS or copy adjustment is justified for this pass.

## Pass 31 Critique

The opened "What each source supports" disclosure has the right coverage-to-bloat balance. It appears after the source field, dry-run action, fixed guarantee, upload fallback, source type, and editable settings, so opening it adds proof without displacing the primary journey. The six cards are source-family summaries rather than a source matrix: they cover GitHub repositories and files, WordPress REST/WXR content, feeds and OPML, server paths, browser-selected files, archives, documents, SQL, XML, Markdown, HTML, PDF, EPUB, plain text, linked assets, and staging paths without adding alternate result states or source-specific flows.

No coverage gap in the opened disclosure justifies expanding the HTML. The card labels match the source selector families, and the format examples are broad enough for reviewer confidence while staying compact. Adding per-format rows, examples, compatibility rules, or separate WordPress/feed/archive journeys would reintroduce the matrix problem this pass is trying to avoid.

Recommendation: keep `docs/importer/user-journey-10x-clarity.html` stable. No tiny copy adjustment is justified in this pass.

## Pass 32 Critique

The tablet-width result remains readable after the recent trims. The dry-run counts fit in one row, the result table stays inside the panel, and the visible columns still let a reviewer scan outcome, item, evidence, owner, and next action without switching context.

Item-level evidence is still reviewable at this width. The skipped-title row retains the necessary chain: `Skip`, `Untitled appendix`, `appendix/no-title.md`, `No readable title found`, `Editor`, and `Add title or skip`. The warning row also still leads cleanly into remediation: `Block rendering` identifies the missing `images/block-rendering.svg`, the evidence says the linked media is absent from the source checkout, and the `Replace media` action matches the selected replacement panel below.

Recommendation: keep `docs/importer/user-journey-10x-clarity.html` stable. No layout or copy adjustment is justified for this pass.

## Pass 33 Critique

The mobile scan-decision state explains the interruption without extra policy copy. "Scan decision," "Needs decision," and "Handle old-site URLs" establish why the dry run stopped, while the sentence about 7 links in 3 draft pages gives the concrete scope and says the dry run cannot finish until the choice is made.

The two radio choices are enough because each names the behavior and the consequence: keep the links unchanged in the preview, or rewrite known old URLs to matching draft pages while reporting unresolved links. The affected-content proof is also appropriately small: one old URL example, three affected page names, and a "View affected content" action. That gives confidence without turning the card into a URL policy document.

The action pair is clear in context. "Apply decision and continue" advances the paused dry run with the selected handling, and "Pause dry run" gives a safe exit without adding another explanation block. The progress stack below reinforces that this is an active dry run waiting on URL handling, not a new setup step.

Recommendation: keep `docs/importer/user-journey-10x-clarity.html` stable. No tiny copy or layout adjustment is justified for this pass; adding more policy text would make this mobile state heavier without improving the decision.

## Pass 34 Critique

The desktop full-journey screenshot still tells one coherent importer story. It starts with a focused source setup, makes the first write boundary safe through the fixed dry-run guarantee, pauses only for a specific old-site URL decision, resumes into item-level dry-run evidence, shows one remediation example for missing media, and ends with a consequence-explicit real-import gate. The page reads as one guided path rather than a catalog of importer capabilities.

The current length is justified by the job it is proving. The setup summary, scan progress, result counts, evidence table, warning notes, remediation card, and final consequence list each answer a different user question: what source is being previewed, why the dry run paused, what would change, which issues remain, how one issue can be resolved, and what WordPress will write if the real import runs. Removing any of those would save space but weaken the source-to-dry-run-to-import narrative.

The only visible density is in the result and real-import sections, but it is useful density rather than bloat. The result table keeps create, update, warning, and skip evidence in one review surface, and the final amber gate is explicit that the real import creates and updates drafts, publishes zero items, keeps old-site URLs external, skips the untitled document, and carries one media warning into the report. That is the right level of consequence before a destructive action.

Recommendation: keep `docs/importer/user-journey-10x-clarity.html` stable. No tiny copy or layout adjustment is justified for this pass. Future changes should wait for reviewer feedback on a concrete misunderstanding, not another pre-review trim.

## Pass 35 Critique

The 500px mobile dry-run result cards are readable enough to keep. The stacked transformation makes each table row self-contained: outcome, item, evidence, owner, and next action stay in a predictable order, and the count cards above give the user the overall shape before the detail list. The section is long, but it is useful length rather than bloat because the result area is the first place where the user needs item-level proof before writing content.

The warning replacement path has the right amount of evidence. The warning card names `Block rendering`, shows the missing `images/block-rendering.svg` source path, explains that linked media is absent from the checkout, assigns ownership to the content owner, and keeps `Replace media` next to the item. The selected resolution panel then shows affected draft, missing file, replacement file, and changed import consequence. That is enough to support action without adding a full media-management flow.

The skipped-title path is also sufficiently supported. The skipped card names `Untitled appendix`, keeps `appendix/no-title.md`, gives the reason as "No readable title found," assigns the editor, and offers `Add title or skip`. The open-warnings summary and real-import gate continue the consequence by saying the title warning can be fixed or confirmed as a skip, and unresolved skipped content will not be imported. That chain is compact and clear.

Recommendation: keep `docs/importer/user-journey-10x-clarity.html` stable. No tiny copy or layout adjustment is justified for this pass; reducing the stacked cards would save pixels at the cost of the evidence needed to act.

## Pass 36 Critique

The final mobile handoff remains consequence-explicit even after the long dry-run result section. The "Ready for real import" heading, amber "Allowed with warnings" badge, non-blocking warning sentence, draft-only button label, and exact consequence list all re-establish the write boundary before the user can run the real import. The user does not have to remember details from the result cards: the handoff repeats that 9 drafts are created, 3 drafts are updated, 0 items publish, 7 old-site URLs stay external, 1 untitled document is skipped, and 1 media warning carries into the final report.

The tiny "After running" block is still useful rather than bloat. It is short, stays inside the pre-run gate, and answers the natural last question: where the user lands after pressing the real-import button. Because it only promises draft links, update review, the skipped item, and the final report, it does not become a success dashboard, retry queue, rollback story, or expanded completion state.

Recommendation: keep `docs/importer/user-journey-10x-clarity.html` stable. No tiny copy or layout adjustment is justified for this pass. The only future edit worth considering would be a one-sentence "After running" trim if reviewers specifically say the final mobile gate feels heavy, but the current 500px evidence does not justify that change.

## Pass 37 Critique

The opened "Upload files instead" disclosure has the right clarity-to-bloat balance. In the 500px mobile screenshot, the primary URL/path route still comes first: source field, Browse, helper text, "Start dry run," and the fixed dry-run guarantee all appear before the upload fallback. Opening the disclosure adds one browser file picker and one short helper sentence, so it does not steal priority from the main paste-or-path journey.

Coverage is clear enough for the optional path. The helper names local archives, folders, documents, feed lists, and exports that are not available at a URL, which covers browser-selected files, folders, local archive/export cases, and non-URL feed lists without turning the setup panel into another source matrix. The source-type grid immediately below still reinforces "Browser upload" and "Archive or document" as secondary source families.

Recommendation: keep `docs/importer/user-journey-10x-clarity.html` stable. No tiny copy or layout adjustment is justified for this pass; adding more upload examples would create bloat, and moving the disclosure lower would make the legitimate local-file fallback harder to find.

## Pass 38 Critique

The desktop setup hierarchy remains clear rather than bloated with the upload disclosure closed. The primary path is still visually dominant: source type, source field, "Start dry run," and the fixed dry-run guarantee sit together in the main panel, while optional upload appears as a single collapsed disclosure after the guarantee. That ordering keeps browser upload available without making it compete with the paste-or-path route.

The editable settings and current setup summary still help more than they distract. The three settings are compact, consequence-focused, and below the main action, so they read as import defaults rather than setup prerequisites. The summary panel earns its space because it repeats the selected source, path, preview-only mode, draft behavior, URL policy, and next steps in one glance; it supports confidence before preview instead of introducing another decision.

The collapsed source-support disclosure remains secondary. It is available after the required action, guarantee, optional upload fallback, and settings, so it preserves feature proof without reintroducing a visible source matrix. On desktop, the right rail plus this disclosure do make the setup section informationally dense, but the density is aligned with review confidence and does not obscure what the user should do first.

Recommendation: keep `docs/importer/user-journey-10x-clarity.html` stable. No tiny copy or layout adjustment is justified for this pass.

## Pass 39 Critique

The tablet setup-to-scan transition reads as one continuous flow rather than bloat. The source action stays clear at the top: selected source type, source field, Browse, "Start dry run," and the fixed dry-run guarantee are grouped tightly enough that the user can see the required action before optional upload, settings, or support details.

The setup summary earns its own block at tablet width. It repeats the GitHub folder, long path, preview-only mode, draft behavior, and URL policy, then turns those choices into the next three steps. That makes the handoff into the scan decision feel intentional instead of like a separate demo panel.

The URL handling decision, progress stack, and result counts also connect cleanly. The scan decision names the 7 links in 3 draft pages, the selected URL handling option explains the consequence, the progress stack shows URL handling as the current pause, and the dry-run result counts appear immediately below with create/update/skip/warning totals. The page is long, but the visible sequence answers "what source, what decision, what is paused, what result follows" without adding another explanatory layer.

Recommendation: keep `docs/importer/user-journey-10x-clarity.html` stable. No copy or layout adjustment is justified for this pass; trimming the setup summary or progress stack would save space but weaken the tablet handoff.

## Pass 40 Critique

The 900px tablet journey is long but still coherent from setup through scan decision, dry-run result, and the real-import gate. The repeated consequence copy is doing useful work at the major boundaries: setup says dry run is safe, the scan decision explains why the preview is paused, the dry-run result shows item-level evidence, and the final gate restates exactly what WordPress will write.

The one high-confidence hierarchy issue is in the final gate. In the supplied tablet screenshot, the green "Run real import as drafts" button appears before the "Before running" remediation list, so the final chances to publish immediately, import the skipped appendix, or clear media warnings sit after the action they are meant to inform. That hides risk at the only write boundary.

Recommendation: tighten the opening gate copy around the highest-risk promise, then move the existing "Before running" bullets into the amber real-import gate above the action row. This preserves every feature and every consequence, removes the separate sidecar block at tablet width, and makes the final run decision read in the right order: draft-only safety, allowed-with-warnings reason, write consequences, last pre-run choices, then the green action.

## Pass 41 Critique

The 500px mobile journey improves after the Pass 40 final-gate reorder. The final write boundary now reads in the right order: allowed-with-warnings state, draft-only promise, exact write consequences, "Before running" remediation choices, then the green "Run real import as drafts" action. That fixes the high-confidence tablet issue without adding a new state or expanding the feature surface.

The repeated warnings are still dense, but not heavy enough to justify another HTML edit. The warning language appears at different decision points: result evidence, unresolved warning summary, final allowed-with-warnings gate, and post-run report expectation. On mobile that creates a long read, but each repetition protects a specific risk before content is written. Removing one now would likely hide why the import is allowed, what is skipped, or what remains in the report.

The green action is not pushed too far down in the supplied screenshot. It sits below the "Before running" bullets, which is the correct hierarchy for a destructive write boundary, and the button remains visible within the final gate before the short "After running" note. "Before running" also does not read like a blocker because the section gives optional consequence-changing routes rather than validation errors, while the surrounding copy and enabled green action clearly say the run is allowed with non-blocking warnings.

Publish risk is visible enough: the gate says drafts only, the consequence list says "Publish 0 items," the "Before running" list says changing the draft setting is required to publish immediately, and the button label repeats "as drafts." The final report promise also stays appropriately small because "After running" promises draft/update links, skipped-item status, and the remaining media warning report without becoming a post-import dashboard.

Recommendation: keep `docs/importer/user-journey-10x-clarity.html` stable. No mobile-specific regression in the provided 500px evidence justifies a design edit. If later reviewers still find the final gate heavy, the smallest future trim would be notes or PR-copy framing around why warnings repeat at write boundaries, not another pre-review UI change.

## Pass 42 Critique

The desktop/tall screenshot confirms the Pass 40 hierarchy fix: "Before running" now appears inside the amber gate before the green "Run real import as drafts" action, so the final write boundary preserves the mobile-safe order and does not hide the last publish, skipped-appendix, or media-warning choices after the action.

The concrete regression is layout-only. Removing the sidecar left the real-import section using the shared two-column `.state-body` grid, so the final amber gate remains in the old narrow left column while a large empty white column sits to its right. That makes the final gate look weaker than the earlier result and scan sections even though it is the highest-risk decision.

Recommendation: keep the content stable and make only the scoped layout fix: let `#real-import .state-body` use one column, then use the recovered desktop width to pair the consequence list with "Before running" inside the same amber gate. Do not add another panel, summary, or explanatory copy to fill the empty space; the issue is stale layout, not missing information.

## Pass 43 Critique

The desktop dry-run result area has one high-confidence review-friction issue in the supplied screenshot: the `Next action` column is clipped and the result table shows a horizontal scrollbar even though the visible data is only four compact rows. That makes the key actions read as "Review", "Replace", and "Add titl" instead of the actual review choices, so the user has to scroll sideways before acting on the warning or skip.

The warning ownership and resolver placement are otherwise strong enough to keep. The warning row names the content owner, the open-warnings panel repeats that media belongs to the content owner and title belongs to the editor, and the resolver panel sits directly under the table with the selected `Block rendering` warning still visible nearby. The count cards remain acceptable as a summary because the table immediately below samples each outcome type and the final gate repeats the exact consequences.

Recommendation: keep the content stable and make only the scoped table-layout fix. Use fixed table layout, explicit column widths, and wrapping link-style actions so the existing action text is visible without horizontal scrolling. Do not add another warning panel or expand the counts; the concrete problem is clipped action affordance, not missing feature surface.

## Pass 44 Critique

The selected resolver panel has one high-confidence design-only defect in the supplied desktop screenshot: the `Replacement` label collides with its value and reads as `Replacementuploads/importer/block-rendering.svg`. That makes the replacement path look malformed even though the actual path is short, relevant, and otherwise readable. The `Affected`, `Missing`, and `Result` rows are clear, and the panel is correctly scoped to one selected missing-media issue.

Path wrapping does not need a new resolver workflow. The existing `.path-value` wrapping handles the missing and replacement file paths, and the panel already shows the evidence chain: affected draft, missing source file, replacement file, and changed import consequence. The only necessary UI change is to give resolver labels enough column width so label/value separation stays intact.

Recommendation: keep the resolver content and workflow stable. Make the scoped CSS fix by widening the resolver label column; do not add upload progress, file validation, bulk replacement, or another issue-resolution state for this PR.

## Pass 45 Critique

The 768px tablet result journey holds up after the recent table and resolver fixes. The dry-run table stays dense, but it does not collapse into the earlier failure mode: outcome pills fit, item paths wrap without forcing a horizontal scroll, evidence remains readable, owners are short enough for the fixed column, and the action column preserves full labels like "Review match," "Replace media," and "Add title or skip." The table therefore keeps enough hierarchy for a reviewer to understand what happened and what can be acted on.

The resolver panel also reads correctly at this width. Its labels no longer collide with values, the 124px label column is not wasting enough space to starve the replacement path, and the two resolver actions retain a clear primary/secondary relationship. The long GitHub source path in setup is crowded but still contained with the Browse button intact, so it is an acceptable mid-width compromise rather than a regression.

The warning summary below the resolver feels connected enough because it immediately follows the result panel, carries the "Dry run complete" state, and lists the same remaining duplicate, media, title, and old-site-link consequences surfaced in the table and resolver. It is long, but it is not detached from the result journey.

Recommendation: keep `docs/importer/user-journey-10x-clarity.html` stable. No concrete tablet-only design defect in the provided 768px evidence justifies another CSS or copy edit.

## Pass 46 Critique

The opened source-support disclosure at 768px is informative without taking over the setup task. The primary action path still appears first: source type tabs, source URL field, Browse, "Start dry run," the fixed dry-run guarantee, optional upload disclosure, and editable settings all precede the support cards. That ordering keeps the cards as proof of coverage rather than a competing chooser.

The six support cards are large, but their content is not feature-proof bloat. Each card maps to one source type tab and gives compact examples: GitHub repositories and docs, WordPress posts/media/WXR, feeds and OPML, server folders/archives/local exports, browser-selected files, and archive/document formats including ZIP, WXR, Markdown, HTML, PDF, EPUB, XML, SQL, OPML, and plain text. The visible format coverage is broad enough for the setup promise, and adding more examples would make this disclosure heavier without improving the next action.

The tablet wrapping is acceptable. Source type tabs wrap to two or three lines in a few labels, but the selected GitHub tab remains clear, the tab row height stays stable, and the support cards below use readable short lines rather than clipped text. The current setup panel immediately after the disclosure restores hierarchy by summarizing the chosen source, path, mode, draft behavior, URL policy, and next steps before the scan decision.

Recommendation: keep `docs/importer/user-journey-10x-clarity.html` stable. No concrete design-only issue in the supplied 768px open-disclosure evidence justifies an HTML or CSS edit; the best change for this pass is to avoid adding more supported-format proof.

## Pass 47 Critique

The 500px opened source-support disclosure is long, but it does not make source selection feel like a second workflow. The mobile order still protects the current setup task: source field, Browse, "Start dry run," fixed dry-run guarantee, upload fallback, source type grid, editable settings, and only then the support disclosure. Opening "What each source supports" therefore reads as optional coverage proof after the setup choices, not a required branch before preview.

The support cards preserve the needed source coverage without visible wrapping failures. The six cards map cleanly to the six source types and cover GitHub repositories/docs/assets, WordPress posts/media/authors/comments/REST/WXR, feeds and OPML, server folders/archive paths/local exports, browser-selected files, and archive/document formats including ZIP, Markdown, HTML, PDF, EPUB, XML, SQL, OPML, and plain text. The card text wraps into short readable lines, and the long GitHub source path in the setup summary below wraps instead of overflowing.

The only mobile cost is vertical length: the opened disclosure delays the "Ready to preview" summary by roughly one screen. That is acceptable because the disclosure is explicitly user-opened and answers a legitimate "does my source work?" question. Collapsing or combining the cards would save space, but it would either hide missing source coverage or make the support proof harder to scan.

Recommendation: keep `docs/importer/user-journey-10x-clarity.html` stable. No concrete 500px design-only issue justifies an HTML or CSS edit for this pass; the best constraint is to avoid adding more cards, examples, or source-specific journeys inside the disclosure.

## Pass 48 Critique

The 1440px desktop full journey is holding its shape after the recent breakpoint and table fixes. The page still has several panels, but they are now doing distinct jobs instead of stacking redundant explanations: setup captures the source and safety defaults, the summary explains the current preview state, the scan decision pauses on one URL policy choice, the result table provides item-level evidence, and the final gate states exactly what WordPress will write. The first action is also strong enough because the URL field and blue "Start dry run" button appear before optional upload, settings, and support disclosure.

The result and final gate are better balanced than in earlier passes. The dry-run section remains dense, but the four-row table, selected resolver, and warning summary make the review work visible without forcing horizontal scrolling or clipped action labels in the supplied screenshot. The final amber gate is full-width, consequence-first, and ordered correctly: allowed-with-warnings state, draft-only promise, write consequences, pre-run choices, green action, then after-running links. Warning repetition is present, but it is attached to different risk boundaries rather than duplicated in the same panel.

No layout artifact from the breakpoint changes is high-confidence enough to justify a CSS edit. The left rail, setup/sidebar split, scan progress stack, result two-column layout, and final single-column gate all align cleanly at 1440px. The main remaining cost is page length, but compressing the result or final gate now would remove evidence that makes the write decision safer.

Recommendation: keep `docs/importer/user-journey-10x-clarity.html` stable. Do not add more panels, warning callouts, or source-proof copy; the best next step is preserving the current feature surface and letting review focus on whether the journey model itself is right.

## Pass 49 Critique

The 500px mobile full journey is long, but the length is mostly earned by distinct import decisions rather than accidental duplication. The first action is not obscured: the source field, Browse affordance, "Start dry run" button, and fixed dry-run guarantee are all visible near the top before optional upload, settings, support proof, scan decision, result review, and final write gate. Recent CSS changes also appear stable at this width: no horizontal table scroll is visible, resolver labels do not collide with values, long paths wrap, and the final gate keeps the remediation list above the green action.

The main cumulative cost is vertical fatigue in the result-to-import stretch. The dry-run table, selected missing-media resolver, warning summary, and final allowed-with-warnings gate repeat the same unresolved media/title/skipped-item facts several times. On mobile that makes the last third feel heavier than the setup and scan states. However, each repetition sits at a different risk boundary: item evidence, selected resolution, remaining warnings, write consequences, and post-run report expectation. Trimming one inside this static artifact would likely make the real-import decision less explicit.

The result cards and resolver labels are acceptable in the supplied screenshot. The four result counts are compact, the table rows are tall but readable, action labels remain complete, and the selected resolver preserves label/value separation for affected content, missing path, replacement, and result. The final gate is visually heavy, but it is appropriately heavy for the only content-writing action, and its order is now correct: allowed state, draft-only intent, exact consequences, before-running choices, action, then after-running links.

Recommendation: keep `docs/importer/user-journey-10x-clarity.html` stable for this pass. Do not make another CSS or copy trim from the 500px evidence; the remaining mobile fatigue is a product-review tradeoff, not a concrete design-only defect. If reviewers later ask for a lighter mobile result flow, the next exploration should test a dedicated mobile result-review pattern rather than shaving warnings from this static journey.

## Pass 50 Critique

The 430px first viewport after the shell-height fix does not show a high-confidence design defect. The supplied screenshot crops the right edge of the top "Import" nav label and the long GitHub source value, but a 430px browser check reports no page overflow: the document scroll width equals the viewport width, the mobile nav fits inside the sidebar, the source field fits inside the setup card, and the source type grid cells remain contained. That matches the known headless capture pattern rather than proving a concrete DOM/CSS overflow issue.

The first action is still reached quickly enough. The viewport opens with the product title, four-step nav, "Import content," "Add source," the required source field, Browse fallback, helper copy, the full-width "Start dry run" action, and the fixed dry-run guarantee. Optional upload and the source type grid are visible below the action, but they do not delay the primary paste-or-path route. The source type grid also holds its two-column mobile layout without clipped labels or unstable row heights.

Recommendation: keep `docs/importer/user-journey-10x-clarity.html` stable. Do not edit the HTML or CSS for the apparent right-edge cropping in this screenshot; it is not reproducible as layout overflow at 430px and changing the nav or source field now would be churn.

## Pass 51 Critique

The 768px tablet first viewport holds after the shell-height fix. The dark header is still stable, the active Setup tab is clear, the page title and setup state badge are not compressed, and there is no visible artifact from `min-height: 0` on the collapsed shell. The setup card keeps the source choice, source field, Browse fallback, "Start dry run" action, dry-run guarantee, upload disclosure, and the top of editable settings in a coherent first-screen order.

The source type tabs are the tightest part of the viewport. Several labels wrap across two or three lines, especially "Archive or document," but the six source choices remain legible, evenly bounded, and visibly tab-like. This does not justify another breakpoint or collapsing the chooser because the selected GitHub tab is obvious and the primary source field/action hierarchy still appears immediately below it. The URL text is truncated in the input, but that is expected for a long source path and the Browse button remains subordinate to the field rather than competing with "Start dry run."

Settings begin at the bottom of the viewport, but they do not delay the first action. The first dry-run CTA appears before optional upload and settings, and the green dry-run guarantee reinforces the safety model without adding another panel. No concrete tablet-only defect in the supplied screenshot warrants editing the HTML or CSS.

Recommendation: keep `docs/importer/user-journey-10x-clarity.html` stable. Do not add a compact tab mode, shrink the header, or move settings upward for this pass; the current 768px first viewport preserves the feature surface while keeping the first action reachable.

## Pass 52 Critique

The 1440px desktop setup/scan-decision view is mostly stable, but the remaining clarity issue is hierarchy rather than missing capability. The primary source task is present and correctly ordered: source type, source field, Browse fallback, "Start dry run," fixed dry-run guarantee, optional upload, editable settings, and collapsed source support. That is not setup bloat in the old sense because the optional pieces sit after the action and the support inventory stays collapsed.

The right "Current setup" summary is useful, but it is visually close to becoming a competing first task. It repeats the long GitHub path, mode, draft behavior, URL policy, and next three steps while the user is still in the source-entry state. On this wide desktop view the summary helps reviewers understand what will happen next, yet its bold "Ready to preview" heading and numbered steps pull attention away from the source field and blue dry-run action. If the next pass edits setup, the safest adjustment would be to make the summary quieter or more explicitly post-action: keep source, mode, drafts, and URLs, but reduce the prominence of the step list until after dry run starts.

The scan decision starts a little abruptly because it appears as a full new surface immediately after the setup card, with only the right summary's step list previewing the handoff. The decision itself is clear once reached: it names the seven old-site links, shows the selected URL handling choice, gives an affected-content route, and pairs the pause with progress status. The weak point is the transition, not the decision content. A future design pass could add a small "After preview starts" handoff line or visually connect the setup summary's "Resolve duplicate and URL decisions" step to the scan panel, but that is polish rather than a blocker.

Recommendation: do not edit `docs/importer/user-journey-10x-clarity.html` for this pass. There is no high-confidence design-only defect in the supplied desktop evidence; the current setup keeps the primary action reachable and the scan decision understandable. Preserve the feature surface, and only consider a future quieting of the current-setup summary if reviewers say it competes with source entry.

## Pass 53 Critique

The 768px tablet setup state holds after the desktop-only settings-row change. The editable settings no longer try to force three cards into a narrow row; they settle into a two-column grid with the third card alone on the next row. That creates a small visual imbalance, but it is preferable to cramped labels or tiny setting cards, and it does not delay the source task because the source field, Browse fallback, "Start dry run," and fixed dry-run guarantee all remain above settings.

The source field is tight but acceptable at this width. The long GitHub path is clipped inside the text input, which is normal input behavior rather than page overflow, and the Browse button remains visible without stealing hierarchy from the dry-run action. The current setup summary below also handles the long path correctly: the path wraps within the summary list instead of spilling out of the surface.

The disclosure hierarchy is still clear. "Upload files instead" remains a secondary disclosure inside the source card, and "What each source supports" stays collapsed after editable settings, so the setup does not become a source-support catalog. The desktop-only rule therefore appears scoped correctly: it improves wide desktop settings alignment without causing tablet crowding or unintended overflow.

Recommendation: keep `docs/importer/user-journey-10x-clarity.html` stable. No concrete 768px design-only issue in the supplied setup evidence justifies a CSS or copy edit; the mild settings raggedness is an acceptable tablet tradeoff.

## Pass 54 Critique

The 1440px desktop result-to-final-import journey remains clear enough to preserve. The settings row is visible in the upper setup area, but it no longer dominates the page: the blue dry-run action, green safety guarantee, scan decision, result evidence, and final green import action all carry stronger hierarchy. The row reads as safety defaults rather than a competing configuration step.

The dry-run result is dense, but the density is doing useful work. Counts, item evidence, owner, next action, selected resolver, and remaining warnings are all visible without table overflow or clipped controls. The resolver placement directly below the table is still the right tradeoff because it connects the selected warning to a concrete fix before the user reaches the final gate.

Warning repetition is present across the dry-run side panel and final gate, but it does not read as accidental duplication in this desktop view. The right panel explains what remains open after preview; the final gate reframes those same facts as write consequences before "Run real import as drafts." The final hierarchy is appropriate: allowed-with-warnings state, draft-only promise, consequence list, before-running guidance, primary import action, and then after-running links.

No layout artifact from the recent CSS changes is visible in the supplied screenshot. The result grid, resolver box, warning side panel, and amber final gate align cleanly, and the lower gate content remains readable even though the screenshot crops before the full after-running area.

Recommendation: keep `docs/importer/user-journey-10x-clarity.html` stable. Do not edit the HTML or CSS for this pass; the remaining cost is intentional review density, not a concrete design-only regression.

## Pass 55 Critique

The 500px mobile full journey holds after the recent desktop/tablet setup changes. The shell is stable: the dark header fits the viewport, the four journey tabs remain readable, and there is no visible horizontal-scroll regression. The setup state also keeps the right order on mobile: source field, Browse fallback, "Start dry run," fixed dry-run guarantee, optional upload, source type, editable settings, and collapsed source-support proof. The settings cards are stacked, legible, and subordinate to the dry-run action rather than becoming a new first task.

Long path handling is acceptable in the supplied evidence. The text input clips the pasted GitHub path, which is expected input behavior, while the "Ready to preview" path wraps inside the summary without pushing the card wider. Resolver labels and values stay separated, and the result rows preserve complete action labels such as "Review match," "Replace media," and "Add title or skip." The final gate also keeps the corrected order: allowed-with-warnings state, draft-only promise, exact write consequences, before-running guidance, green import action, and then after-running links.

The remaining mobile cost is vertical fatigue from the result and final gate, especially where media/title/skipped-item warnings appear in item evidence, selected resolution, warning summary, and final write consequences. That repetition is visible, but it is not accidental enough to justify a trim: each occurrence answers a different question before content is written. Removing one would make the mobile page shorter at the cost of weaker safety or less explicit final-import consequences.

Recommendation: keep `docs/importer/user-journey-10x-clarity.html` stable for this pass. No concrete 500px mobile regression in settings layout, action hierarchy, path wrapping, resolver labels, warning repetition, final gate order, or shell/header behavior justifies an HTML/CSS edit. If future review asks for a shorter mobile experience, test a dedicated mobile result-review pattern rather than shaving isolated warnings from this static full journey.

## Pass 56 Critique

The opened source-support disclosure is useful proof, but in this 1440px desktop state it now competes with the journey more than it should. The primary setup path is still technically ordered correctly: source field, "Start dry run," dry-run guarantee, upload fallback, and editable settings all appear before the support cards. Once the disclosure is open, however, the six-card capability grid becomes a large visual stop between setup and the active "Scan decision" surface. That makes the page briefly read as "choose from everything the importer supports" again instead of "continue the dry run by resolving the current URL decision."

The support cards are especially competitive because they are full-width, evenly weighted, and visually similar to task cards. They use the same white bordered-card language as settings and decision content, and they sit immediately above the scan decision title. In the supplied screenshot, the user's actual next required action is lower on the page in an amber decision panel, while the opened disclosure gets a calmer, broader layout that invites browsing. That is the wrong emphasis after a dry run has started.

The content itself is not bloated. The six source families and examples are still the right feature surface, and the copy remains compact. The defect is placement and visual weight when expanded during a scan-decision state. The disclosure works as setup reassurance when closed, but opened cards should not form a second source-selection surface or push the blocking decision down.

Recommendation: keep the feature coverage, but make the opened support proof less competitive with the first dry-run path and scan decision. The smallest design direction is to keep "What each source supports" collapsed by default and treat the expanded content as lightweight reference: fewer card-like boxes, lower contrast, smaller spacing, or a more compact inline list. If the dry run is active or paused on a decision, the scan decision should visually outrank any expanded setup reference. Do not add new source examples, alternate journeys, or extra explanatory copy; this pass needs hierarchy reduction, not more content.

## Pass 57 Critique

The 500px mobile opened-source-support screenshot does not show the desktop-only support-reference change causing a mobile regression. The setup journey still starts with the right hierarchy: page title, "Add source," required source field, Browse fallback, "Start dry run," and the fixed dry-run guarantee all appear before upload, source type, editable settings, and the opened support proof. That keeps the first mobile job clear even when the optional proof is expanded.

The support disclosure remains secondary on mobile. It appears only after editable settings, its heading is a disclosure label rather than a new state title, and the individual source-support cards read as compact reference examples instead of source-specific journeys. The cards are visually large because mobile stacks everything vertically, but they are user-requested proof below the main action path, not a competing chooser before preview. Text wrapping is clean, labels remain legible, and no card appears to force horizontal overflow.

The main cost is still vertical length. Opening the disclosure pushes later setup summary or scan content farther down by roughly a screen, and the last "Archive or document" card is cropped at the bottom of the supplied evidence. That is acceptable for an optional expanded reference, especially because the disclosure answers a legitimate "does this source work?" question without adding new examples or controls.

Recommendation: keep `docs/importer/user-journey-10x-clarity.html` stable for this pass. The Pass 56 desktop-only hierarchy concern has not reproduced as a mobile defect in the 500px evidence, and no HTML/CSS edit is justified. Preserve the current mobile order and avoid adding more support copy, source-specific journeys, or extra cards inside the disclosure.

## Pass 58 Critique

The 768px tablet opened-source-support screenshot is acceptable. The support cards are visually substantial, but they do not delay the dry-run journey because the source type, source field, Browse fallback, "Start dry run" action, fixed dry-run guarantee, upload fallback, and editable settings all appear before the opened reference. The user can still complete the required setup task without reading the six cards.

The cards do compete mildly with the "Current setup" summary because they sit immediately above it and use the same bordered-card language. That matters less on this tablet view than it did in the desktop scan-decision evidence: the page is still in setup, the support reference was explicitly opened, and the summary remains visible directly below with a strong "Ready to preview" heading and numbered next steps. The support grid answers "does my source type work?" while the summary answers "what happens next?", and both remain distinguishable enough.

The tablet layout is also more efficient than the mobile opened state. The six cards form two compact rows, text wraps cleanly, and the card grid does not create horizontal overflow or push the summary several screens away. Compressing the cards further would save some vertical space but would likely make source coverage harder to scan, which is not a worthwhile tradeoff for an optional disclosure.

Recommendation: keep `docs/importer/user-journey-10x-clarity.html` stable. No tablet-specific edit is justified from this evidence; the opened support proof has some visual weight, but it does not block the dry-run action or bury the current setup summary enough to warrant another layout pass. Continue to avoid adding source examples, extra cards, or new controls inside the disclosure.

## Pass 59 Critique

The 1440px desktop first viewport provides enough orientation even though the scan decision starts below the fold. The left rail names the full journey, the page header states the safe sequence, and the right "Current setup" panel gives a concrete next-step list: scan without writing, resolve duplicate and URL decisions, then review the dry-run result. That is sufficient source-to-dry-run-to-preview framing before the user reaches the scan decision surface.

The primary setup task remains clear. The source type, source path, Browse affordance, "Start dry run" action, and fixed dry-run guarantee are all visible in the main panel, with optional upload and editable settings placed after the action. The right summary repeats the selected source and safety defaults, but in this screenshot it reads as confirmation rather than a second form; it helps explain what will happen after the dry run starts.

No extra above-the-fold handoff is needed. Adding a preview banner, inline scan-decision teaser, or expanded support proof would make the first viewport heavier without solving a concrete misunderstanding. The only visible tradeoff is that the scan-decision state itself is just below the first screen, but the sidebar step labels and numbered setup summary already cover that transition.

Recommendation: keep `docs/importer/user-journey-10x-clarity.html` stable. Do not edit the HTML for this pass; the desktop first viewport is doing enough orientation work while preserving a lean setup task and the same feature surface.

## Pass 60 Critique

The full 1440px desktop flow still reads as one guided journey. The left rail, page header, setup card, current-setup summary, scan decision, dry-run result, and final real-import gate all reinforce the same sequence: add a source, preview without writing, resolve the one blocking URL decision, inspect evidence, then run the real import only after WordPress has shown the consequences. The page is long, but it does not feel like separate disconnected mockups because each section carries forward concrete state from the previous one.

The final real-import gate is doing the right consequence work before the green action. It leads with "Allowed with warnings," states "Run real import: creates drafts, skips unresolved items," repeats that drafts only means nothing publishes, then lists exact write outcomes: create 9 draft pages, update 3 matched drafts, publish 0 items, keep 7 old-site URLs external, skip 1 untitled document, and carry 1 unresolved media warning into the final report. The "Before running" box also gives the user practical ways to change the outcome before committing. That is clear enough to support an enabled green "Run real import as drafts" action because the non-blocking warning policy is explicit.

The remaining issue is small hierarchy risk, not missing communication. The amber final gate contains a lot of repeated warning language from the dry-run result, and the green button appears inside a large caution surface rather than as the only dominant endpoint. In this full-flow evidence, that repetition is acceptable because it changes context from "warnings still open" to "here is exactly what WordPress will write." Trimming it would make the gate lighter, but it would also weaken the safety promise immediately before the irreversible action.

Recommendation: keep `docs/importer/user-journey-10x-clarity.html` stable and do not edit the HTML for this pass. The final gate communicates consequences before the green action, the full journey remains coherent, and the artifact preserves the same feature surface without adding visible bloat. Future production work can tune copy density, but this design loop should not reopen layout or feature scope from the Pass 60 evidence.

## Pass 61 Critique

The 500px mobile full-flow screenshot keeps the journey understandable from setup through the real-import gate. The page is long, but the order still holds: source entry and dry-run action, fixed no-write guarantee, editable safety settings, scan decision, dry-run evidence, selected issue resolution, warning summary, and only then the final write decision. That sequence is safety-focused rather than bloated because each major section answers a different question at the point where the user needs it.

The mobile final gate communicates consequences before the green action well enough. It leads with the non-blocking policy, names the exact run mode as drafts, says nothing publishes, and lists the concrete outcomes before "Run real import as drafts": create 9 pages, update 3 matched draft pages, publish 0 items, keep 7 old-site URLs external, skip 1 untitled document, and carry 1 unresolved media warning into the report. The "Before running" box also gives clear ways to change those outcomes before committing. That is enough context for an enabled green action because the unresolved media warning is explicitly framed as allowed, not silently ignored.

The main mobile cost is repetition in the lower half. The same media/title/skipped-item facts appear in result details, selected resolution, remaining warnings, and the final gate. In a production mobile UI, that could become tiring and might be better served by a focused result-review screen with collapsed evidence. In this static full-flow artifact, the repetition is still defensible: result rows prove evidence, the selected issue proves remediation, the warning summary proves remaining risk, and the final gate translates the same facts into write consequences.

Recommendation: keep `docs/importer/user-journey-10x-clarity.html` stable for this pass. Do not edit the HTML to shorten the mobile flow or lighten the final gate; the current density is appropriately safety-focused for the content-writing boundary and does not add new feature surface. If future feedback asks for less repetition, explore a dedicated mobile review pattern rather than removing consequence copy from the final gate.
