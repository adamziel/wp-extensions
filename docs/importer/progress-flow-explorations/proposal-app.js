const DATA = {"designer-01-journey-01.html":{"title":"Guided Source Start","summary":"Source, mode, and safety choices stay visible until the user starts.","variant":"paste","index":1},"designer-01-journey-02.html":{"title":"Fast Paste With Preview","summary":"A pasted URL is recognized immediately and converted into a precise import summary.","variant":"preview","index":2},"designer-01-journey-03.html":{"title":"GitHub Path Discovery","summary":"The first seconds name the repository operation instead of showing a dead queue.","variant":"github","index":3},"designer-01-journey-04.html":{"title":"Dry Run First","summary":"Unfamiliar sources can be checked first without leaving the main flow.","variant":"dryrun","index":4},"designer-01-journey-05.html":{"title":"Browser Folder Import","summary":"Upload progress and server-side import progress are separate, visible phases.","variant":"upload","index":5},"designer-02-journey-06.html":{"title":"First Ten Seconds","summary":"The pending state explains worker handoff, discovery, and unknown totals.","variant":"pending","index":6},"designer-02-journey-07.html":{"title":"Stage-first Progress","summary":"Stages carry meaning before percentages become reliable.","variant":"stages","index":7},"designer-02-journey-08.html":{"title":"Activity-first Progress","summary":"The activity log reassures operators during long discovery and retry windows.","variant":"activity","index":8},"designer-02-journey-09.html":{"title":"Compact Status Rail","summary":"A persistent rail keeps source, mode, lock, and phase visible without clutter.","variant":"rail","index":9},"designer-02-journey-10.html":{"title":"Clear Unknown Total","summary":"The UI avoids pretending that 0 / ? is progress.","variant":"unknown","index":10},"designer-03-journey-11.html":{"title":"GitHub Browse To Import","summary":"Browsing ends with an explicit selected path and a single primary action.","variant":"browse","index":11},"designer-03-journey-12.html":{"title":"URL Decisions Inline","summary":"Link rewriting pauses become understandable, bounded decisions.","variant":"links","index":12},"designer-03-journey-13.html":{"title":"Warning Resolution","summary":"Relationship warnings point to the affected draft and the required fix.","variant":"warnings","index":13},"designer-03-journey-14.html":{"title":"Abort And Resume","summary":"Stopping an import explains what remains and what can continue.","variant":"abort","index":14},"designer-03-journey-15.html":{"title":"Long Import Operator","summary":"Large imports separate current item, totals, retries, and next action.","variant":"long","index":15},"designer-04-journey-16.html":{"title":"One-screen Impatient","summary":"The fastest path keeps source, mode, progress, and review in one dense surface.","variant":"one","index":16},"designer-04-journey-17.html":{"title":"Minimal Choices","summary":"Advanced controls are visible as compact toggles, not another setup screen.","variant":"minimal","index":17},"designer-04-journey-18.html":{"title":"Review-first Done","summary":"Completion leads directly to imported drafts and session evidence.","variant":"done","index":18},"designer-04-journey-19.html":{"title":"Problem State Recovery","summary":"Failures show the failed candidate, the next automatic attempt, and manual actions.","variant":"recovery","index":19},"designer-04-journey-20.html":{"title":"Dashboard Queue","summary":"Multiple sessions can be scanned by status, phase, and required action.","variant":"queue","index":20}}; 
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
