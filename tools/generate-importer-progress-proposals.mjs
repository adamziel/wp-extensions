import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const outDir = 'docs/importer/progress-flow-explorations';

const proposals = [
	['designer-01-journey-01.html', 'Guided Source Start', 'Source, mode, and safety choices stay visible until the user starts.', 'paste'],
	['designer-01-journey-02.html', 'Fast Paste With Preview', 'A pasted URL is recognized immediately and converted into a precise import summary.', 'preview'],
	['designer-01-journey-03.html', 'GitHub Path Discovery', 'The first seconds name the repository operation instead of showing a dead queue.', 'github'],
	['designer-01-journey-04.html', 'Dry Run First', 'Unfamiliar sources can be checked first without leaving the main flow.', 'dryrun'],
	['designer-01-journey-05.html', 'Browser Folder Import', 'Upload progress and server-side import progress are separate, visible phases.', 'upload'],
	['designer-02-journey-06.html', 'First Ten Seconds', 'The pending state explains worker handoff, discovery, and unknown totals.', 'pending'],
	['designer-02-journey-07.html', 'Stage-first Progress', 'Stages carry meaning before percentages become reliable.', 'stages'],
	['designer-02-journey-08.html', 'Activity-first Progress', 'The activity log reassures operators during long discovery and retry windows.', 'activity'],
	['designer-02-journey-09.html', 'Compact Status Rail', 'A persistent rail keeps source, mode, lock, and phase visible without clutter.', 'rail'],
	['designer-02-journey-10.html', 'Clear Unknown Total', 'The UI avoids pretending that 0 / ? is progress.', 'unknown'],
	['designer-03-journey-11.html', 'GitHub Browse To Import', 'Browsing ends with an explicit selected path and a single primary action.', 'browse'],
	['designer-03-journey-12.html', 'URL Decisions Inline', 'Link rewriting pauses become understandable, bounded decisions.', 'links'],
	['designer-03-journey-13.html', 'Warning Resolution', 'Relationship warnings point to the affected draft and the required fix.', 'warnings'],
	['designer-03-journey-14.html', 'Abort And Resume', 'Stopping an import explains what remains and what can continue.', 'abort'],
	['designer-03-journey-15.html', 'Long Import Operator', 'Large imports separate current item, totals, retries, and next action.', 'long'],
	['designer-04-journey-16.html', 'One-screen Impatient', 'The fastest path keeps source, mode, progress, and review in one dense surface.', 'one'],
	['designer-04-journey-17.html', 'Minimal Choices', 'Advanced controls are visible as compact toggles, not another setup screen.', 'minimal'],
	['designer-04-journey-18.html', 'Review-first Done', 'Completion leads directly to imported drafts and session evidence.', 'done'],
	['designer-04-journey-19.html', 'Problem State Recovery', 'Failures show the failed candidate, the next automatic attempt, and manual actions.', 'recovery'],
	['designer-04-journey-20.html', 'Dashboard Queue', 'Multiple sessions can be scanned by status, phase, and required action.', 'queue'],
];

const css = `
:root {
	--bg: #f0f0f1;
	--ink: #1d2327;
	--muted: #646970;
	--line: #dcdcde;
	--panel: #fff;
	--blue: #3858e9;
	--blue-dark: #1e3a8a;
	--green: #008a20;
	--amber: #996800;
	--red: #b32d2e;
	--soft-blue: #eef6fc;
	--soft-green: #edf8ef;
	--soft-amber: #fcf9e8;
	--soft-red: #fcf0f1;
	--shadow: 0 12px 32px rgba(29, 35, 39, .08);
}

* { box-sizing: border-box; }

body {
	background: var(--bg);
	color: var(--ink);
	font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
	font-size: 14px;
	line-height: 1.45;
	margin: 0;
}

button, input, select, textarea {
	font: inherit;
}

.proposal-shell {
	display: grid;
	grid-template-rows: auto 1fr;
	min-height: 700px;
}

.admin-bar {
	align-items: center;
	background: #1d2327;
	color: #f0f0f1;
	display: flex;
	gap: 18px;
	height: 38px;
	padding: 0 18px;
}

.admin-bar strong { font-size: 13px; }
.admin-bar span { color: #c3c4c7; font-size: 12px; }

.wp-admin {
	display: grid;
	grid-template-columns: 188px minmax(0, 1fr);
	min-height: 662px;
}

.menu {
	background: #2c3338;
	color: #f0f0f1;
	padding: 14px 0;
}

.menu div {
	align-items: center;
	display: flex;
	gap: 8px;
	min-height: 34px;
	padding: 7px 16px;
}

.menu .current {
	background: #2271b1;
	font-weight: 700;
}

.content {
	padding: 22px 24px 28px;
}

.screen-header {
	align-items: end;
	display: grid;
	gap: 16px;
	grid-template-columns: minmax(0, 1fr) auto;
	margin-bottom: 16px;
}

h1, h2, h3, p { margin-top: 0; }
h1 { font-size: 24px; line-height: 1.2; margin-bottom: 4px; }
h2 { font-size: 16px; margin-bottom: 10px; }
h3 { font-size: 13px; margin-bottom: 6px; }
p { margin-bottom: 10px; }

.muted { color: var(--muted); }

.layout {
	display: grid;
	gap: 16px;
	grid-template-columns: minmax(360px, 1fr) 330px;
}

.panel {
	background: var(--panel);
	border: 1px solid var(--line);
	border-radius: 6px;
	box-shadow: var(--shadow);
	min-width: 0;
}

.panel-pad { padding: 16px; }

.source-strip {
	background: #fff;
	border: 1px solid var(--line);
	border-radius: 6px;
	display: grid;
	gap: 10px;
	grid-template-columns: minmax(0, 1fr) auto;
	padding: 12px;
}

.source-input {
	background: #f6f7f7;
	border: 1px solid var(--line);
	border-radius: 4px;
	font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
	min-height: 42px;
	overflow-wrap: anywhere;
	padding: 10px;
}

.actions, .chips, .tabs, .decision-actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.button {
	align-items: center;
	background: var(--blue);
	border: 1px solid var(--blue);
	border-radius: 4px;
	color: #fff;
	cursor: pointer;
	display: inline-flex;
	font-weight: 600;
	gap: 7px;
	min-height: 34px;
	padding: 7px 12px;
	text-decoration: none;
}

.button:focus,
.chip:focus,
.repo-row:focus,
select:focus {
	box-shadow: 0 0 0 2px #fff, 0 0 0 4px var(--blue);
	outline: 0;
}

.button.secondary {
	background: #fff;
	color: var(--blue);
}

.button.subtle {
	background: transparent;
	border-color: var(--line);
	color: var(--ink);
}

.chip {
	background: #fff;
	border: 1px solid var(--line);
	border-radius: 999px;
	color: var(--muted);
	cursor: pointer;
	font-size: 12px;
	font-weight: 700;
	padding: 6px 10px;
}

.chip.active {
	background: var(--blue);
	border-color: var(--blue);
	color: #fff;
}

.grid {
	display: grid;
	gap: 12px;
}

.two { grid-template-columns: repeat(2, minmax(0, 1fr)); }
.three { grid-template-columns: repeat(3, minmax(0, 1fr)); }

.card {
	background: #fff;
	border: 1px solid var(--line);
	border-radius: 6px;
	padding: 12px;
}

.soft-blue { background: var(--soft-blue); }
.soft-green { background: var(--soft-green); }
.soft-amber { background: var(--soft-amber); }
.soft-red { background: var(--soft-red); }

.status-line {
	align-items: center;
	display: grid;
	gap: 10px;
	grid-template-columns: auto minmax(0, 1fr) auto;
	margin-bottom: 14px;
}

.badge {
	border-radius: 999px;
	font-size: 12px;
	font-weight: 700;
	padding: 5px 9px;
}

.badge.blue { background: var(--soft-blue); color: var(--blue-dark); }
.badge.green { background: var(--soft-green); color: var(--green); }
.badge.amber { background: var(--soft-amber); color: var(--amber); }
.badge.red { background: var(--soft-red); color: var(--red); }

.progress {
	background: #dcdcde;
	border-radius: 999px;
	height: 12px;
	overflow: hidden;
}

.progress span {
	background: linear-gradient(90deg, #3858e9, #008a20);
	display: block;
	height: 100%;
	transition: width .25s ease;
	width: var(--progress, 9%);
}

.stage-list {
	display: grid;
	gap: 8px;
}

.stage {
	align-items: start;
	background: #fff;
	border: 1px solid var(--line);
	border-left: 4px solid var(--line);
	border-radius: 6px;
	display: grid;
	gap: 2px;
	grid-template-columns: 28px minmax(0, 1fr);
	padding: 10px;
}

.stage.done { border-left-color: var(--green); }
.stage.active { border-left-color: var(--blue); background: var(--soft-blue); }
.stage.warn { border-left-color: var(--amber); background: var(--soft-amber); }

.stage-index {
	align-items: center;
	background: #f0f0f1;
	border-radius: 999px;
	display: inline-flex;
	font-size: 12px;
	font-weight: 700;
	height: 24px;
	justify-content: center;
	width: 24px;
}

.activity {
	display: grid;
	gap: 8px;
	max-height: 184px;
	overflow: auto;
}

.event {
	border-left: 3px solid var(--line);
	padding-left: 10px;
}

.event.active { border-color: var(--blue); }
.event.warn { border-color: var(--amber); }
.event.good { border-color: var(--green); }

.decision-box {
	border-top: 1px solid var(--line);
	display: none;
	margin-top: 14px;
	padding-top: 14px;
}

.decision-box.visible { display: block; }

.drawer {
	background: #fbfbfc;
	border-top: 1px solid var(--line);
	padding: 14px 16px;
}

.drawer summary {
	cursor: pointer;
	font-weight: 700;
}

.drawer[open] {
	display: block;
}

.repo-browser {
	border: 1px solid var(--line);
	border-radius: 6px;
	overflow: hidden;
}

.repo-row {
	align-items: center;
	background: #fff;
	border-bottom: 1px solid var(--line);
	display: grid;
	gap: 10px;
	grid-template-columns: auto minmax(0, 1fr) auto;
	padding: 9px 12px;
}

.repo-row:last-child { border-bottom: 0; }
.repo-row.selected { background: var(--soft-blue); }

.review-table {
	border-collapse: collapse;
	width: 100%;
}

.review-table th,
.review-table td {
	border-bottom: 1px solid var(--line);
	padding: 8px;
	text-align: left;
}

.toast {
	background: #1d2327;
	border-radius: 6px;
	bottom: 18px;
	color: #fff;
	display: none;
	left: 50%;
	padding: 10px 14px;
	position: fixed;
	transform: translateX(-50%);
	z-index: 5;
}

.toast.visible { display: block; }

.metric-row {
	display: grid;
	gap: 8px;
	grid-template-columns: repeat(4, minmax(0, 1fr));
}

.metric {
	background: #fff;
	border: 1px solid var(--line);
	border-radius: 6px;
	padding: 10px;
}

.metric strong {
	display: block;
	font-size: 18px;
}

@media (max-width: 920px) {
	.wp-admin { grid-template-columns: 1fr; }
	.menu { display: none; }
	.layout, .two, .three { grid-template-columns: 1fr; }
	.screen-header { display: block; }
}
`;

const js = `
const DATA = ${JSON.stringify(Object.fromEntries(proposals.map(([file, title, summary, variant], index) => [file, { title, summary, variant, index: index + 1 }])))}; 
const file = location.pathname.split('/').pop();
const proposal = DATA[file] || DATA['designer-01-journey-01.html'];

const variants = {
	paste: ['Guided source intake', 'Recognize the source, show safety defaults, start once.'],
	preview: ['Paste recognized as GitHub', 'Owner, repo, ref, and path are confirmed before start.'],
	github: ['Repository discovery', 'Sparse Git fetch is active; totals appear after discovery.'],
	dryrun: ['Dry run confidence check', 'Discover work without writing pages.'],
	upload: ['Browser upload handoff', 'Upload transfer is distinct from importer processing.'],
	pending: ['First worker tick', 'The UI explains the first 10 seconds of waiting.'],
	stages: ['Stage-led progress', 'Read source is active before item totals exist.'],
	activity: ['Activity-led progress', 'Recent events carry reassurance.'],
	rail: ['Compact operator rail', 'Source, mode, and lock state stay fixed.'],
	unknown: ['Unknown totals handled honestly', 'No fake 0 / ? progress.'],
	browse: ['GitHub browse selection', 'The selected folder is explicit.'],
	links: ['URL treatment decision', 'First-party domains are confirmed inline.'],
	warnings: ['Relationship warning', 'The affected draft and fields are visible.'],
	abort: ['Abort with consequences', 'Stop future work without implying rollback.'],
	long: ['Long import operations', 'Current item, total, retry state, and next phase are visible.'],
	one: ['One-screen import', 'Fast path with dense but readable controls.'],
	minimal: ['Minimal choices', 'Only the choices that change output are on the surface.'],
	done: ['Review-first completion', 'The primary action after done is review drafts.'],
	recovery: ['Problem recovery', 'Failure, next attempt, and operator actions are clear.'],
	queue: ['Session queue dashboard', 'Multiple imports are scannable by state.'],
};

const variantInsights = {
	paste: ['Paste or drop remains the fastest path.', 'Safety defaults are visible before start.', 'The form collapses into progress after launch.'],
	preview: ['Recognized GitHub fields remove guesswork.', 'Mode and link treatment are confirmed in one scan.', 'The next action is still a single Start button.'],
	github: ['Repository, ref, and path stay visible while fetching.', 'The first activity event explains why totals are unknown.', 'Candidate retries appear as status, not raw failure spam.'],
	dryrun: ['Dry run is framed as a source check.', 'The same controls can continue into draft import.', 'No content is written during the check.'],
	upload: ['Browser upload and server import are separate phases.', 'Large-folder limits are visible before transfer.', 'Clear selection is available before start.'],
	pending: ['Worker handoff is named directly.', 'The count appears after discovery, not as 0 / ?.', 'Keepalive status is visible.'],
	stages: ['Stage labels carry meaning before percentages.', 'The active stage has concrete work text.', 'Layout remains stable between ticks.'],
	activity: ['Recent events are concise and operational.', 'The active event explains current work.', 'Technical details remain collapsed.'],
	rail: ['The status rail preserves source, mode, and lock state.', 'The center panel stays focused on current action.', 'Secondary controls remain reachable.'],
	unknown: ['Unknown totals are a named state.', 'The UI previews what determinate progress will look like later.', 'No false precision appears.'],
	browse: ['Directory selection is keyboard-operable.', 'Selected path updates the import source.', 'The browser keeps focus in the task.'],
	links: ['URL treatment blocks exactly one stage.', 'Domain choices are understandable check chips.', 'Resume is the primary action after resolution.'],
	warnings: ['Relationship mapping uses form controls, not raw JSON.', 'Affected draft context stays visible.', 'Advanced context is behind details.'],
	abort: ['Abort is secondary and consequence-aware.', 'Already-created content is not implied to roll back.', 'Resume remains the safe default.'],
	long: ['Large import progress shows current item and totals.', 'Retries stay in activity, not the headline.', 'The active stage shows bounded current work.'],
	one: ['A dense single screen supports impatient users.', 'Options are compact but not hidden.', 'Review remains the end target.'],
	minimal: ['Only output-changing choices are surfaced.', 'Advanced settings are visible but quiet.', 'Start remains one primary action.'],
	done: ['Completion shifts emphasis to review.', 'Draft counts and warnings are summarized.', 'The session record remains available.'],
	recovery: ['The failed candidate and next attempt are clear.', 'Manual actions do not interrupt automatic retry.', 'Technical details are available on demand.'],
	queue: ['Multiple sessions can be scanned quickly.', 'Blocked sessions show the next operator action.', 'Done sessions point to review.'],
};

let state = {
	phase: proposal.variant === 'done' ? 5 : proposal.variant === 'links' || proposal.variant === 'warnings' ? 2 : proposal.variant === 'long' ? 3 : 0,
	progress: proposal.variant === 'done' ? 100 : proposal.variant === 'long' ? 58 : proposal.variant === 'upload' ? 64 : 9,
	mode: proposal.variant === 'dryrun' ? 'Dry run' : 'Creates drafts',
	source: 'https://github.com/WordPress/gutenberg/tree/trunk/docs/explanations/architecture',
	details: false,
	decision: proposal.variant === 'links' || proposal.variant === 'warnings',
	selectedRepoPath: 'docs/explanations/architecture',
};

const stageNames = ['Read source', 'Prepare content', 'URL treatment', 'Import media', 'Write pages', 'Finish'];

function esc(text) {
	return String(text).replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
}

function statusForVariant() {
	if (proposal.variant === 'done') return ['Done', 'green', '39 pages created as drafts.'];
	if (proposal.variant === 'links' || proposal.variant === 'warnings') return ['Needs attention', 'amber', 'Import is paused until this decision is resolved.'];
	if (proposal.variant === 'recovery') return ['Retrying', 'red', 'A GitHub candidate failed; the next candidate is being tried.'];
	if (state.phase === 0) return ['Starting', 'blue', 'File count appears after GitHub repository discovery.'];
	if (state.phase < 5) return ['Running', 'blue', state.progress + '% · work is moving through ' + stageNames[state.phase] + '.'];
	return ['Done', 'green', 'Imported drafts are ready for review.'];
}

function renderStages() {
	return stageNames.map((name, index) => {
		let cls = index < state.phase ? 'done' : index === state.phase ? 'active' : '';
		if ((proposal.variant === 'links' || proposal.variant === 'warnings') && index === 2) cls = 'warn';
		const detail = index === 0 && state.phase === 0 ? 'Fetching repository files with sparse Git.' :
			index === 2 && state.decision ? 'Waiting for an operator decision.' :
			index < state.phase ? 'Complete.' : 'Not started.';
		return '<div class="stage ' + cls + '"><span class="stage-index">' + (index + 1) + '</span><div><strong>' + name + '</strong><p class="muted">' + detail + '</p></div></div>';
	}).join('');
}

function renderActivity() {
	const events = [
		['good', 'Import session created from the WordPress admin page.'],
		['good', 'GitHub repository fetch queued.'],
		[state.phase === 0 ? 'active' : 'good', state.phase === 0 ? 'Fetching repository files through sparse Git.' : 'Repository files queued as source items.'],
		[proposal.variant === 'recovery' ? 'warn' : '', proposal.variant === 'recovery' ? 'Candidate trunk/docs failed; trying next path candidate.' : 'Keepalive is attached and will continue the session.'],
		[state.decision ? 'warn' : '', state.decision ? 'Decision required before media import and writes continue.' : 'Next stage is ready.'],
	];
	return events.map(([cls, text]) => '<div class="event ' + cls + '">' + esc(text) + '</div>').join('');
}

function renderVariantInsights() {
	const items = variantInsights[proposal.variant] || variantInsights.paste;
	return '<div class="grid three">' + items.map((item) => '<div class="metric"><strong>' + esc(item.split(' ')[0]) + '</strong><span>' + esc(item) + '</span></div>').join('') + '</div>';
}

function renderRepoBrowser() {
	const rows = ['docs', 'docs/explanations', 'docs/explanations/architecture', 'packages', 'lib/compat'];
	return '<div class="repo-browser">' + rows.map((row) => '<button class="repo-row ' + (row === state.selectedRepoPath ? 'selected' : '') + '" data-path="' + esc(row) + '"><span>Folder</span><strong>' + esc(row) + '</strong><span>Select</span></button>').join('') + '</div>';
}

function renderDecision() {
	if (proposal.variant === 'warnings') {
		return '<h2>Map imported relationships</h2><p>Post 456 has an unmapped author and series. Choose local targets, then continue.</p><div class="grid two"><label class="card">Author<select><option>Editor</option><option>Administrator</option></select></label><label class="card">Series<select><option>Architecture notes</option><option>Uncategorized</option></select></label></div>';
	}
	return '<h2>Confirm first-party domains</h2><p>Only confirm domains that should become links to this WordPress site.</p><div class="chips"><button class="chip active">developer.wordpress.org</button><button class="chip">github.com</button><button class="chip">make.wordpress.org</button></div>';
}

function renderMainPanel() {
	const variant = variants[proposal.variant] || variants.paste;
	let special = '';
	if (proposal.variant === 'upload') special = '<div class="panel-pad"><h2>Selected folder</h2><div class="grid two"><div class="card soft-blue"><strong>42 files</strong><p>18 Markdown, 9 HTML, 4 PDFs, 11 media references.</p></div><div class="card"><strong>Transfer</strong><p>Browser upload 64%; server import starts after staging.</p></div></div></div>';
	if (proposal.variant === 'browse') special = '<div class="panel-pad"><h2>Repository browser</h2>' + renderRepoBrowser() + '</div>';
	if (proposal.variant === 'done') special = '<div class="panel-pad"><h2>Imported drafts</h2><table class="review-table"><tr><th>Page</th><th>Status</th><th>Action</th></tr><tr><td>Architecture overview</td><td>Draft</td><td>Review</td></tr><tr><td>Data flow</td><td>Draft</td><td>Review</td></tr><tr><td>Templates</td><td>Draft</td><td>Review</td></tr></table></div>';
	if (proposal.variant === 'queue') special = '<div class="panel-pad grid"><div class="card soft-blue"><strong>Starting</strong><p>GitHub discovery. Count appears after discovery.</p></div><div class="card"><strong>Running</strong><p>23% · 12 / 52 items complete.</p></div><div class="card soft-amber"><strong>Needs attention</strong><p>Confirm first-party domains.</p></div><div class="card soft-green"><strong>Done</strong><p>Review 18 drafts.</p></div></div>';
	return '<section class="panel">' +
		'<div class="panel-pad stack">' +
		'<div><h2>' + esc(variant[0]) + '</h2><p class="muted">' + esc(variant[1]) + '</p></div>' +
		'<div class="source-strip"><div><h3>Current import</h3><div class="source-input">' + esc(state.source) + '</div></div><button class="button secondary" data-action="recognize">Recognize</button></div>' +
		'<div class="grid three"><div class="card soft-blue"><h3>Source</h3><p>GitHub tree URL</p></div><div class="card soft-green"><h3>Output</h3><p>' + esc(state.mode) + '</p></div><div class="card"><h3>Links</h3><p>Ask before rewriting unknown domains.</p></div></div>' +
		renderVariantInsights() +
		'<div class="actions"><button class="button" data-action="start">Start / advance</button><button class="button secondary" data-action="dryrun">Toggle dry run</button><button class="button subtle" data-action="details">Technical details</button></div>' +
		'</div>' + special +
		'<div class="decision-box ' + (state.decision ? 'visible' : '') + ' panel-pad">' + renderDecision() + '<div class="decision-actions"><button class="button" data-action="resolve">Resolve and continue</button><button class="button secondary" data-action="details">Show context</button></div></div>' +
		'<details class="drawer" ' + (state.details ? 'open' : '') + '><summary>Technical details</summary><p class="muted">Worker lock active. Requested ref: trunk/docs. Source path: /. Last event: github.fetch_queued.</p><p class="muted">Source items: queued 0, processing 1, failed 0. Prepared docs: 0. Media: waiting. Remote backoff: none.</p></details>' +
		'</section>';
}

function render() {
	const [label, tone, note] = statusForVariant();
	document.title = proposal.title;
	document.body.innerHTML = '<div class="proposal-shell">' +
		'<div class="admin-bar"><strong>WordPress</strong><span>Tools</span><span>Universal Importer</span><span>' + esc(proposal.title) + '</span></div>' +
		'<div class="wp-admin"><nav class="menu"><div>Dashboard</div><div>Posts</div><div>Pages</div><div>Media</div><div class="current">Tools</div><div>Settings</div></nav>' +
		'<main class="content"><header class="screen-header"><div><h1>' + esc(proposal.title) + '</h1><p class="muted">' + esc(proposal.summary) + '</p></div><span class="badge blue">Proposal ' + proposal.index + '</span></header>' +
		'<div class="layout">' + renderMainPanel() +
		'<aside class="grid">' +
		'<section class="panel panel-pad" aria-live="polite"><div class="status-line"><span class="badge ' + tone + '">' + label + '</span><div class="progress" role="progressbar" aria-label="Import progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' + state.progress + '" style="--progress:' + state.progress + '%"><span></span></div><strong>' + state.progress + '%</strong></div><p class="muted">' + esc(note) + '</p></section>' +
		'<section class="panel panel-pad"><h2>Import stages</h2><div class="stage-list">' + renderStages() + '</div></section>' +
		'<section class="panel panel-pad"><h2>Activity</h2><div class="activity">' + renderActivity() + '</div></section>' +
		'</aside></div></main></div><div class="toast" id="toast">Updated</div></div>';
}

function toast(text) {
	const el = document.getElementById('toast');
	if (!el) return;
	el.textContent = text;
	el.classList.add('visible');
	setTimeout(() => el.classList.remove('visible'), 1200);
}

document.addEventListener('click', (event) => {
	const button = event.target.closest('button');
	if (!button) return;
	const action = button.dataset.action;
	if (button.dataset.path) {
		state.selectedRepoPath = button.dataset.path;
		state.source = 'https://github.com/WordPress/gutenberg/tree/trunk/' + state.selectedRepoPath;
		toast('Selected ' + state.selectedRepoPath);
		render();
		return;
	}
	if (button.classList.contains('chip')) {
		button.classList.toggle('active');
		toast(button.classList.contains('active') ? 'Domain selected' : 'Domain left unchanged');
		return;
	}
	if (action === 'start') {
		state.phase = Math.min(5, state.phase + 1);
		state.progress = state.phase === 5 ? 100 : Math.max(state.progress + 18, 18);
		toast(state.phase === 5 ? 'Import complete' : 'Advanced to ' + stageNames[state.phase]);
		render();
	}
	if (action === 'dryrun') {
		state.mode = state.mode === 'Dry run' ? 'Creates drafts' : 'Dry run';
		toast(state.mode);
		render();
	}
	if (action === 'details') {
		state.details = !state.details;
		render();
	}
	if (action === 'recognize') {
		state.progress = Math.max(state.progress, 12);
		toast('GitHub source recognized');
		render();
	}
	if (action === 'resolve') {
		state.decision = false;
		state.phase = Math.max(state.phase + 1, 3);
		state.progress = Math.max(state.progress, 46);
		toast('Decision resolved');
		render();
	}
});

render();
`;

function page(title, script = 'proposal-app.js') {
	return `<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>${title}</title>
	<link rel="stylesheet" href="proposal-app.css">
</head>
<body>
	<script src="${script}"></script>
</body>
</html>
`;
}

function index() {
	const cards = proposals.map(([file, title, summary], index) => `
		<section class="proposal-frame" id="proposal-${index + 1}">
			<header>
				<div>
					<h2>${String(index + 1).padStart(2, '0')} ${title}</h2>
					<p>${summary}</p>
				</div>
				<a href="${file}" target="_blank" rel="noopener">Open full page</a>
			</header>
			<iframe src="${file}" loading="lazy" title="${title}"></iframe>
		</section>`).join('');
	return `<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Importer Progress Flow Explorations</title>
	<style>
		:root { --bg:#f0f0f1; --ink:#1d2327; --muted:#646970; --line:#dcdcde; --panel:#fff; --blue:#3858e9; }
		* { box-sizing:border-box; }
		body { background:var(--bg); color:var(--ink); font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; font-size:14px; line-height:1.45; margin:0; }
		main { margin:0 auto; max-width:1500px; padding:24px; }
		.hero { align-items:end; display:grid; gap:16px; grid-template-columns:minmax(0,1fr) auto; margin-bottom:16px; }
		h1 { font-size:28px; line-height:1.2; margin:0 0 6px; }
		h2 { font-size:17px; margin:0 0 4px; }
		p { color:var(--muted); margin:0; }
		.links { display:flex; flex-wrap:wrap; gap:8px; }
		a { color:var(--blue); font-weight:700; text-decoration:none; }
		.links a, .proposal-frame header a { background:#fff; border:1px solid var(--line); border-radius:999px; padding:7px 10px; }
		.proposal-frame { background:var(--panel); border:1px solid var(--line); border-radius:8px; margin-bottom:18px; overflow:hidden; }
		.proposal-frame header { align-items:center; border-bottom:1px solid var(--line); display:grid; gap:12px; grid-template-columns:minmax(0,1fr) auto; padding:12px 14px; }
		iframe { border:0; display:block; height:1040px; width:100%; }
		@media (max-width: 760px) { main { padding:14px; } .hero, .proposal-frame header { display:block; } iframe { height:1160px; } }
	</style>
</head>
<body>
<main>
	<header class="hero">
		<div>
			<h1>Importer Progress Flow Explorations</h1>
			<p>High-fidelity clickable proposals embedded as stacked iframes. Use the controls inside each frame to advance stages, resolve decisions, toggle dry run, select paths, and reveal technical details.</p>
		</div>
		<nav class="links">
			<a href="existing-flow-map.html">Flow map</a>
			<a href="flow-critique.md">Critique</a>
			<a href="import-flow-research.md">Research</a>
		</nav>
	</header>
	${cards}
</main>
</body>
</html>
`;
}

mkdirSync(outDir, { recursive: true });
writeFileSync(join(outDir, 'proposal-app.css'), css.trim() + '\n');
writeFileSync(join(outDir, 'proposal-app.js'), js.trim() + '\n');
writeFileSync(join(outDir, 'index.html'), index().replace(/\n[ \t]+\n/g, '\n\n'));

for (const [file, title] of proposals) {
	writeFileSync(join(outDir, file), page(title));
}

for (const group of [1, 2, 3, 4]) {
	const groupProposals = proposals.slice((group - 1) * 5, group * 5);
	const text = '# Designer 0' + group + ' Notes\n\n' + groupProposals.map(([file, title, summary], offset) => `${offset + 1}. [${title}](${file}) - ${summary}`).join('\n') + '\n';
	writeFileSync(join(outDir, `designer-0${group}-notes.md`), text);
}
