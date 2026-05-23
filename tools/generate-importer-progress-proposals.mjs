import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const outDir = 'docs/importer/progress-flow-explorations';

const proposals = [
	{ file: 'designer-01-journey-01.html', title: 'Pipeline Console', summary: 'A deployment-console style import pipeline with status, blockers, and run controls.', type: 'timeline', accent: '#3858e9' },
	{ file: 'designer-01-journey-02.html', title: 'Spreadsheet Mapper', summary: 'A grid-first mapper for bulk inspection, field assignment, and source-to-WordPress matching.', type: 'ops', accent: '#2271b1' },
	{ file: 'designer-01-journey-03.html', title: 'Card-based Field Matching', summary: 'A tactile board that matches source cards to WordPress destinations.', type: 'matrix', accent: '#4f46e5' },
	{ file: 'designer-01-journey-04.html', title: 'Command Center Dashboard', summary: 'A dense operations dashboard for queues, recent runs, failures, retries, and scheduled jobs.', type: 'queue', accent: '#3858e9' },
	{ file: 'designer-01-journey-05.html', title: 'Split Preview Studio', summary: 'A before-and-after workstation with source on one side and WordPress output on the other.', type: 'inspector', accent: '#0a7cba' },
	{ file: 'designer-02-journey-06.html', title: 'Validation Inbox', summary: 'An inbox-style triage surface for warnings, missing media, duplicate slugs, and blockers.', type: 'ledger', accent: '#996800' },
	{ file: 'designer-02-journey-07.html', title: 'Import Kanban', summary: 'Record readiness is managed across Ready, Needs Mapping, Warnings, Failed, and Imported lanes.', type: 'kanban', accent: '#4f46e5' },
	{ file: 'designer-02-journey-08.html', title: 'Schema Blueprint Builder', summary: 'A structural canvas for post types, taxonomies, authors, media, and custom fields.', type: 'lens', accent: '#006ba1' },
	{ file: 'designer-02-journey-09.html', title: 'Step Wizard With Live Rail', summary: 'A guided setup flow with persistent live summary, counts, mappings, and risks.', type: 'rail', accent: '#3858e9' },
	{ file: 'designer-02-journey-10.html', title: 'Diff Review Interface', summary: 'A Git-style comparison view for accepting, rejecting, or overriding incoming changes.', type: 'unknown', accent: '#996800' },
	{ file: 'designer-03-journey-11.html', title: 'Media Import Workbench', summary: 'A media-first workbench for found images, missing files, alt text, and gallery assignment.', type: 'staging', accent: '#2271b1' },
	{ file: 'designer-03-journey-12.html', title: 'Rules Engine Builder', summary: 'A rule-block interface for transformations, routing, slugs, defaults, and conditional mapping.', type: 'urlboard', accent: '#996800' },
	{ file: 'designer-03-journey-13.html', title: 'Timeline Runner', summary: 'A vertical execution timeline for batches, checkpoints, pauses, retries, and recovery.', type: 'cockpit', accent: '#996800' },
	{ file: 'designer-03-journey-14.html', title: 'Content Type Tabs', summary: 'A content-type-first setup for Pages, Posts, Terms, Media, Users, and custom post types.', type: 'command', accent: '#2271b1' },
	{ file: 'designer-03-journey-15.html', title: 'Minimal Power Panel', summary: 'A compact expert panel with collapsible advanced sections and inline progress.', type: 'fast', accent: '#008a20' },
	{ file: 'designer-04-journey-16.html', title: 'Relationship Graph Inspector', summary: 'A relationship-focused inspector for posts, parents, authors, terms, and media dependencies.', type: 'resolver', accent: '#3858e9' },
	{ file: 'designer-04-journey-17.html', title: 'Batch QA Review', summary: 'A quality-control queue for sampling, approving, and flagging imported record batches.', type: 'report', accent: '#2271b1' },
	{ file: 'designer-04-journey-18.html', title: 'Source Explorer', summary: 'A file-tree and parsed-structure explorer for XML, CSV, JSON, ZIP, Markdown, and HTML sources.', type: 'picker', accent: '#008a20' },
	{ file: 'designer-04-journey-19.html', title: 'Preset Marketplace Admin', summary: 'A local preset library for repeatable import patterns without becoming a marketing page.', type: 'complete', accent: '#b32d2e' },
	{ file: 'designer-04-journey-20.html', title: 'Import Health Monitor', summary: 'A Site Health style readiness report for permissions, memory, cron, media, duplicates, and schema.', type: 'runbook', accent: '#3858e9' },
];

const stages = ['Read source', 'Prepare content', 'URL treatment', 'Import media', 'Write pages', 'Finish'];

function esc(text) {
	return String(text).replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
}

function css(accent) {
	return `<style>
:root{--bg:#f0f0f1;--ink:#1d2327;--muted:#646970;--line:#dcdcde;--panel:#fff;--accent:${accent};--green:#008a20;--amber:#996800;--red:#b32d2e;--blue-soft:#eef6fc;--green-soft:#edf8ef;--amber-soft:#fcf9e8;--red-soft:#fcf0f1;--shadow:0 10px 30px rgba(29,35,39,.08)}
*{box-sizing:border-box}body{background:var(--bg);color:var(--ink);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:14px;line-height:1.45;margin:0}button,input,select{font:inherit}.adminbar{align-items:center;background:#1d2327;color:#f0f0f1;display:flex;gap:16px;height:38px;padding:0 16px}.adminbar span{color:#c3c4c7;font-size:12px}.wrap{padding:22px 24px 28px}.top{align-items:end;display:grid;gap:12px;grid-template-columns:minmax(0,1fr) auto;margin-bottom:16px}h1{font-size:24px;line-height:1.2;margin:0 0 4px}h2{font-size:16px;margin:0 0 10px}h3{font-size:13px;margin:0 0 6px}p{margin:0 0 10px}.muted{color:var(--muted)}.panel,.card,.notice{background:var(--panel);border:1px solid var(--line);border-radius:6px;box-shadow:var(--shadow)}.panel{padding:16px}.card{padding:12px}.notice{border-left:4px solid var(--accent);padding:10px 12px}.button{background:var(--accent);border:1px solid var(--accent);border-radius:4px;color:#fff;cursor:pointer;font-weight:600;min-height:34px;padding:7px 12px}.button.secondary{background:#fff;color:var(--accent)}.button.subtle{background:transparent;border-color:var(--line);color:var(--ink)}.button:focus,.chip:focus,.row-btn:focus,select:focus,input:focus{box-shadow:0 0 0 2px #fff,0 0 0 4px var(--accent);outline:0}.actions,.chips{display:flex;flex-wrap:wrap;gap:8px}.chip{background:#fff;border:1px solid var(--line);border-radius:999px;color:var(--muted);cursor:pointer;font-size:12px;font-weight:700;padding:6px 10px}.chip.active{background:var(--accent);border-color:var(--accent);color:#fff}.progress{background:#dcdcde;border-radius:999px;height:12px;overflow:hidden}.progress span{background:linear-gradient(90deg,var(--accent),var(--green));display:block;height:100%;transition:width .25s ease;width:var(--p,12%)}.badge{border-radius:999px;display:inline-flex;font-size:12px;font-weight:700;padding:5px 9px}.blue{background:var(--blue-soft);color:#1e3a8a}.green{background:var(--green-soft);color:var(--green)}.amber{background:var(--amber-soft);color:var(--amber)}.red{background:var(--red-soft);color:var(--red)}.grid{display:grid;gap:12px}.two{grid-template-columns:repeat(2,minmax(0,1fr))}.three{grid-template-columns:repeat(3,minmax(0,1fr))}.four{grid-template-columns:repeat(4,minmax(0,1fr))}.source{background:#f6f7f7;border:1px solid var(--line);border-radius:4px;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;overflow-wrap:anywhere;padding:10px}.stage{border:1px solid var(--line);border-left:4px solid var(--line);border-radius:6px;padding:10px}.stage.active{background:var(--blue-soft);border-left-color:var(--accent)}.stage.done{background:var(--green-soft);border-left-color:var(--green)}.stage.warn{background:var(--amber-soft);border-left-color:var(--amber)}.event{border-left:3px solid var(--line);padding-left:10px}.event.active{border-color:var(--accent)}.event.warn{border-color:var(--amber)}.event.good{border-color:var(--green)}details{background:#fbfbfc;border-top:1px solid var(--line);padding:12px}summary{cursor:pointer;font-weight:700}.toast{background:#1d2327;border-radius:6px;bottom:18px;color:#fff;display:none;left:50%;padding:10px 14px;position:fixed;transform:translateX(-50%);z-index:4}.toast.visible{display:block}
.command{display:grid;gap:16px;grid-template-columns:1.2fr .8fr}.command .hero-card{display:grid;gap:12px}.inspector{display:grid;gap:16px;grid-template-columns:320px 1fr}.inspector .evidence{border-left:4px solid var(--accent)}.lens{display:grid;gap:14px;grid-template-columns:1fr 1fr}.lens .candidate{align-items:center;display:grid;gap:8px;grid-template-columns:auto 1fr auto}.report{display:grid;gap:14px;grid-template-columns:1fr 360px}.report .score{font-size:46px;font-weight:700}.staging{display:grid;gap:14px;grid-template-columns:280px 1fr}.staging .file-row{align-items:center;border-bottom:1px solid var(--line);display:grid;grid-template-columns:1fr auto;padding:9px}.timeline{display:grid;gap:12px;grid-template-columns:repeat(5,1fr)}.timeline .node{min-height:150px}.cockpit{display:grid;gap:14px;grid-template-columns:280px 1fr 300px}.cockpit .dial{align-items:center;border:10px solid var(--blue-soft);border-top-color:var(--accent);border-radius:50%;display:flex;font-size:34px;font-weight:700;height:160px;justify-content:center;width:160px}.ledger{display:grid;gap:14px;grid-template-columns:1fr 340px}.ledger .log{max-height:480px;overflow:auto}.rail-layout{display:grid;gap:14px;grid-template-columns:220px 1fr}.railbar{background:#fff;border-right:1px solid var(--line);display:grid;gap:10px;padding:14px}.unknown{display:grid;gap:14px;grid-template-columns:1fr 380px}.skeleton{background:linear-gradient(90deg,#f6f7f7,#fff,#f6f7f7);border:1px solid var(--line);border-radius:6px;height:42px}.picker{display:grid;gap:14px;grid-template-columns:330px 1fr}.tree{border:1px solid var(--line);border-radius:6px;overflow:hidden}.row-btn{align-items:center;background:#fff;border:0;border-bottom:1px solid var(--line);cursor:pointer;display:grid;gap:8px;grid-template-columns:auto 1fr auto;padding:10px 12px;text-align:left;width:100%}.row-btn.selected{background:var(--blue-soft)}.urlboard{display:grid;gap:14px;grid-template-columns:1fr 360px}.domain-card{border-left:4px solid var(--amber)}.resolver{display:grid;gap:14px;grid-template-columns:1fr 1fr}.form-row{display:grid;gap:6px;margin-bottom:12px}.form-row select,.form-row input{border:1px solid var(--line);border-radius:4px;min-height:34px;padding:6px}.abort{display:grid;gap:14px;grid-template-columns:1fr 1fr}.abort .danger{border-left:4px solid var(--red)}.ops table,.complete table{border-collapse:collapse;width:100%}.ops th,.ops td,.complete th,.complete td{border-bottom:1px solid var(--line);padding:9px;text-align:left}.fast{display:grid;gap:14px;grid-template-columns:1fr 300px}.command-palette{background:#fff;border:1px solid var(--line);border-radius:8px;padding:10px}.matrix{display:grid;gap:14px;grid-template-columns:repeat(3,1fr)}.matrix .option{min-height:190px}.complete{display:grid;gap:14px;grid-template-columns:360px 1fr}.runbook{display:grid;gap:14px;grid-template-columns:1fr 360px}.runbook .step{border-left:4px solid var(--red)}.queue{display:grid;gap:14px;grid-template-columns:repeat(4,1fr)}.queue .lane{min-height:540px}.kanban{display:grid;gap:12px;grid-template-columns:repeat(5,1fr)}.kanban .column{min-height:500px}.kanban .ticket{cursor:pointer;margin-bottom:10px}
@media(max-width:900px){.command,.inspector,.lens,.report,.staging,.timeline,.cockpit,.ledger,.rail-layout,.unknown,.picker,.urlboard,.resolver,.abort,.fast,.matrix,.complete,.runbook,.queue,.kanban{grid-template-columns:1fr}.wrap{padding:16px}}
</style>`;
}

function adminStart(proposal) {
	return `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>${esc(proposal.title)}</title>${css(proposal.accent)}</head><body><div class="adminbar"><strong>WordPress</strong><span>Tools</span><span>Universal Importer</span><span>${esc(proposal.title)}</span></div><main class="wrap"><header class="top"><div><h1>${esc(proposal.title)}</h1><p class="muted">${esc(proposal.summary)}</p></div><span class="badge blue">Proposal ${proposals.indexOf(proposal) + 1}</span></header>`;
}

function adminEnd() {
	return `<div class="toast" id="toast">Updated</div><script>
let progress=12;
function toast(text){const el=document.getElementById('toast');el.textContent=text;el.classList.add('visible');setTimeout(()=>el.classList.remove('visible'),1200)}
function setProgress(value){progress=Math.max(0,Math.min(100,value));document.querySelectorAll('[role=progressbar]').forEach(el=>{el.style.setProperty('--p',progress+'%');el.setAttribute('aria-valuenow',progress)});document.querySelectorAll('[data-progress-label]').forEach(el=>el.textContent=progress+'%')}
document.addEventListener('click',event=>{const button=event.target.closest('button');if(!button)return;if(button.matches('.chip')){button.classList.toggle('active');toast(button.classList.contains('active')?'Selected':'Cleared');return}if(button.dataset.path){document.querySelectorAll('.row-btn').forEach(row=>row.classList.remove('selected'));button.classList.add('selected');const source=document.querySelector('[data-source]');if(source)source.textContent='https://github.com/WordPress/gutenberg/tree/trunk/'+button.dataset.path;toast('Selected '+button.dataset.path);return}if(button.dataset.action==='advance'){setProgress(progress+18);toast('Advanced progress')}if(button.dataset.action==='resolve'){document.querySelectorAll('[data-decision]').forEach(el=>el.remove());setProgress(Math.max(progress,52));toast('Decision resolved')}if(button.dataset.action==='dry'){const el=document.querySelector('[data-mode]');if(el){el.textContent=el.textContent==='Dry run'?'Creates drafts':'Dry run';toast(el.textContent)}}})
</script></main></body></html>`;
}

function progressPanel(label = 'Starting', detail = 'File count appears after GitHub repository discovery.', value = 12) {
	return `<div class="panel"><div class="grid"><div class="actions"><span class="badge blue">${label}</span><strong data-progress-label>${value}%</strong></div><div class="progress" role="progressbar" aria-label="Import progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${value}" style="--p:${value}%"><span></span></div><p class="muted">${detail}</p><button class="button" data-action="advance">Advance progress</button></div></div>`;
}

function stagesPanel(active = 0) {
	return `<div class="grid">${stages.map((stage, index) => `<div class="stage ${index < active ? 'done' : index === active ? 'active' : ''}"><strong>${index + 1}. ${stage}</strong><p class="muted">${index < active ? 'Complete.' : index === active ? 'In progress with concrete worker status.' : 'Not started.'}</p></div>`).join('')}</div>`;
}

function technicalDetails() {
	return `<details><summary>Technical details</summary><p class="muted">Worker lock active. Requested ref: trunk/docs. Source items queued 0, processing 1, failed 0. Remote backoff none. Last event github.fetch_queued.</p></details>`;
}

function renderProposal(proposal) {
	const source = '<div class="source" data-source>https://github.com/WordPress/gutenberg/tree/trunk/docs/explanations/architecture</div>';
	let body = '';
	switch (proposal.type) {
		case 'command':
			body = `<section class="command"><div class="panel hero-card"><h2>Start import</h2>${source}<div class="grid three"><div class="card blue"><strong>Source</strong><p>GitHub tree URL</p></div><div class="card green"><strong data-mode>Creates drafts</strong><p>Review before publish</p></div><div class="card"><strong>Links</strong><p>Ask before rewrite</p></div></div><div class="actions"><button class="button" data-action="advance">Start import</button><button class="button secondary" data-action="dry">Dry run</button></div>${technicalDetails()}</div>${progressPanel()}</section>`;
			break;
		case 'inspector':
			body = `<section class="inspector"><aside class="panel grid"><h2>Recognized source</h2><div class="card evidence"><strong>Owner</strong><p>WordPress</p></div><div class="card evidence"><strong>Repository</strong><p>gutenberg</p></div><div class="card evidence"><strong>Ref candidate</strong><p>trunk/docs</p></div></aside><div class="panel grid"><h2>Inspection result</h2>${source}<div class="grid four"><div class="card">Sparse Git available</div><div class="card">Path needs discovery</div><div class="card">Markdown expected</div><div class="card">Count unknown</div></div><div class="actions"><button class="button" data-action="advance">Import recognized source</button><button class="button secondary" data-action="dry">Run dry check</button></div>${progressPanel('Ready', 'Recognition finished; worker starts after import.', 0)}</div></section>`;
			break;
		case 'lens':
			body = `<section class="lens"><div class="panel grid"><h2>Traversal candidates</h2>${['trunk/docs/explanations/architecture','trunk/docs','HEAD/docs'].map((row, index) => `<div class="card candidate ${index===0?'blue':''}"><span class="badge ${index===0?'blue':'amber'}">${index===0?'Trying':'Next'}</span><strong>${row}</strong><button class="button secondary" data-action="advance">Try</button></div>`).join('')}</div><div class="grid">${progressPanel('Fetching', 'Sparse Git checkout is traversing the selected path.', 18)}<div class="panel"><h2>Discovery facts</h2><div class="grid two"><div class="card">Ref: trunk/docs</div><div class="card">Path: /</div><div class="card">Protocol: php-toolkit Git</div><div class="card">Count: after traversal</div></div>${technicalDetails()}</div></div></section>`;
			break;
		case 'report':
			body = `<section class="report"><div class="panel grid"><h2>Dry-run report</h2><div class="grid three"><div class="card"><span class="score">39</span><p>candidate pages</p></div><div class="card"><span class="score">6</span><p>media references</p></div><div class="card amber"><span class="score">1</span><p>decision</p></div></div><table><tr><td>Writes</td><td>None in dry run</td></tr><tr><td>URL treatment</td><td>Needs confirmation</td></tr></table><div class="actions"><button class="button" data-action="advance">Continue as draft import</button><button class="button secondary" data-action="dry">Stay dry</button></div></div>${progressPanel('Dry run done', 'Everything is ready to continue into drafts.', 100)}</section>`;
			break;
		case 'staging':
			body = `<section class="staging"><aside class="panel"><h2>Upload staging</h2><div class="progress" role="progressbar" aria-label="Upload progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="64" style="--p:64%"><span></span></div><p class="muted">27 / 42 files uploaded</p><button class="button secondary" data-action="advance">Simulate transfer</button></aside><div class="panel"><h2>File queue</h2>${['README.md','architecture/index.md','images/diagram.png','notes.pdf'].map((file, index)=>`<div class="file-row"><span>${file}</span><span class="badge ${index<2?'green':'blue'}">${index<2?'Uploaded':'Waiting'}</span></div>`).join('')}</div></section>`;
			break;
		case 'timeline':
			body = `<section class="timeline">${['Session created','Worker lock','Sparse Git fetch','Count files','Queue pages'].map((item,index)=>`<div class="panel node"><span class="badge ${index<2?'green':index===2?'blue':'amber'}">${index+1}</span><h2>${item}</h2><p class="muted">${index===2?'This is the visible replacement for silent waiting.':'Step has a concrete status.'}</p><button class="button secondary" data-action="advance">Tick</button></div>`).join('')}</section>`;
			break;
		case 'cockpit':
			body = `<section class="cockpit"><aside class="panel">${stagesPanel(0)}</aside><div class="panel grid"><h2>Active stage cockpit</h2><div class="dial" data-progress-label>12%</div><p>Read source is fetching repository files with sparse Git. Count appears after discovery.</p><button class="button" data-action="advance">Advance tick</button></div><aside>${progressPanel()}</aside></section>`;
			break;
		case 'ledger':
			body = `<section class="ledger"><div class="panel log grid"><h2>Recent activity</h2>${['Import session created','GitHub repository fetch queued','Worker lock acquired','Fetching through sparse Git','Next: queue discovered files'].map((event,index)=>`<div class="event ${index<2?'good':index===3?'active':''}"><strong>${event}</strong><p class="muted">13:${20+index}:0${index} UTC</p></div>`).join('')}</div><aside>${progressPanel('Running', 'Events explain progress before counts are ready.', 24)}${technicalDetails()}</aside></section>`;
			break;
		case 'kanban':
			body = `<section class="kanban">${['Ready','Needs Mapping','Warnings','Failed','Imported'].map((column,index)=>`<div class="panel column"><h2>${column}</h2>${['Architecture overview','Template parts','Data flow'].slice(0,index===4?2:1).map((item,row)=>`<button class="card ticket ${index===2?'amber':index===3?'red':index===4?'green':'blue'}" data-action="${index===1 || index===2 ? 'resolve' : 'advance'}"><strong>${item}</strong><p class="muted">${index===1?'Assign field mapping.':index===2?'Review warning.':index===3?'Retry source item.':index===4?'Imported draft.':'Ready to import.'}</p></button>`).join('')}</div>`).join('')}</section>`;
			break;
		case 'rail':
			body = `<section class="rail-layout"><aside class="railbar"><h2>Status rail</h2><span class="badge blue">Starting</span><span class="badge green">Drafts</span><span class="badge blue">Keepalive on</span><span class="badge amber">Count pending</span></aside><div class="panel grid"><h2>Current work</h2>${source}${stagesPanel(0)}<button class="button" data-action="advance">Advance</button></div></section>`;
			break;
		case 'unknown':
			body = `<section class="unknown"><div class="panel grid"><h2>Total unknown by design</h2><p>No fake <strong>0 / ?</strong>. The UI names discovery until files are queued.</p><div class="skeleton"></div><div class="skeleton"></div><div class="actions"><button class="button" data-action="advance">Files discovered</button></div></div><aside>${progressPanel('Discovering files', 'Counts and percentages begin after source discovery.', 8)}</aside></section>`;
			break;
		case 'picker':
			body = `<section class="picker"><aside class="panel"><h2>Repository tree</h2><div class="tree">${['docs','docs/explanations','docs/explanations/architecture','packages/block-editor','lib/compat'].map((path,index)=>`<button class="row-btn ${index===2?'selected':''}" data-path="${path}"><span>Folder</span><strong>${path}</strong><span>Select</span></button>`).join('')}</div></aside><div class="panel grid"><h2>Selected directory</h2>${source}<div class="grid three"><div class="card">13 Markdown files</div><div class="card">2 HTML files</div><div class="card">0 hidden files</div></div><button class="button" data-action="advance">Import selected folder</button></div></section>`;
			break;
		case 'urlboard':
			body = `<section class="urlboard"><div class="panel grid" data-decision><h2>Rewrite board</h2>${['developer.wordpress.org','make.wordpress.org','github.com'].map((domain,index)=>`<div class="card domain-card"><h3>${domain}</h3><p class="muted">Example: https://${domain}/block-editor/</p><button class="chip ${index<2?'active':''}">Rewrite this domain</button></div>`).join('')}<button class="button" data-action="resolve">Resolve and continue</button></div><aside>${progressPanel('Needs attention', 'URL treatment blocks media and writes until resolved.', 34)}</aside></section>`;
			break;
		case 'resolver':
			body = `<section class="resolver" data-decision><div class="panel"><h2>Resolve relationships</h2><div class="form-row"><label>Author</label><select><option>Editor</option><option>Administrator</option></select></div><div class="form-row"><label>Series</label><select><option>Architecture notes</option><option>Uncategorized</option></select></div><button class="button" data-action="resolve">Save mappings</button>${technicalDetails()}</div><aside class="panel"><h2>Affected draft</h2><div class="card amber">Post 456: Architecture overview</div><p class="muted">Raw JSON is available only in technical details.</p></aside></section>`;
			break;
		case 'abort':
			body = `<section class="abort"><div class="panel"><h2>Keep importing</h2><p>Worker is running and can safely continue.</p>${progressPanel('Running', '17 / 42 items complete.', 41)}</div><div class="panel danger"><h2>Abort session?</h2><p>Stops future work. Pages already created remain in WordPress.</p><div class="actions"><button class="button secondary" data-action="advance">Keep importing</button><button class="button" data-action="advance">Abort after confirmation</button></div></div></section>`;
			break;
		case 'ops':
			body = `<section class="ops panel"><h2>Operations table</h2><table><tr><th>Phase</th><th>Current item</th><th>Status</th><th>Throughput</th></tr><tr><td>Import media</td><td>images/editor.png</td><td><span class="badge blue">Running</span></td><td>9/min</td></tr><tr><td>Write pages</td><td>Queued</td><td><span class="badge amber">Waiting</span></td><td>-</td></tr><tr><td>Relationships</td><td>None</td><td><span class="badge green">Clean</span></td><td>-</td></tr></table><br>${progressPanel('Running', 'Large imports show current item and retry state.', 58)}</section>`;
			break;
		case 'fast':
			body = `<section class="fast"><div class="panel command-palette"><h2>Command palette import</h2><input class="source" value="/wordpress/wp-content/uploads/source.zip"><div class="chips"><button class="chip active">Drafts</button><button class="chip active">Ask on links</button><button class="chip">Dry run</button><button class="chip active">Media</button></div><div class="actions"><button class="button" data-action="advance">Start</button></div></div><aside>${progressPanel('Ready', 'Dense, one-screen path for impatient operators.', 0)}</aside></section>`;
			break;
		case 'matrix':
			body = `<section class="matrix">${['Create drafts','Rewrite confirmed domains','Import media','Preserve hierarchy','Run as dry check','Keep technical log'].map((item,index)=>`<div class="panel option"><h2>${item}</h2><p class="muted">Output policy choice ${index+1}.</p><button class="chip ${index<4?'active':''}">Enabled</button></div>`).join('')}</section>`;
			break;
		case 'complete':
			body = `<section class="complete"><aside class="panel green"><h2>Done</h2><p><strong>39 drafts</strong></p><p>14 media files, 2 warnings resolved.</p><button class="button" data-action="advance">View imported content</button></aside><div class="panel"><h2>Review drafts</h2><table><tr><th>Page</th><th>Status</th><th>Action</th></tr><tr><td>Architecture overview</td><td>Draft</td><td>Review</td></tr><tr><td>Data flow</td><td>Draft</td><td>Review</td></tr><tr><td>Templates</td><td>Draft</td><td>Review</td></tr></table></div></section>`;
			break;
		case 'runbook':
			body = `<section class="runbook"><div class="panel grid"><h2>Retry runbook</h2>${['Candidate trunk/docs failed','Trying trunk + docs path','Fallback to REST tree if needed'].map((step,index)=>`<div class="card step"><strong>${step}</strong><p class="muted">${index===0?'Actual failure reason is shown here.':'Automatic next step.'}</p></div>`).join('')}<button class="button secondary" data-action="advance">Try next now</button></div><aside>${progressPanel('Retrying', 'The importer will try the next GitHub path candidate.', 20)}${technicalDetails()}</aside></section>`;
			break;
		case 'queue':
			body = `<section class="queue">${['Starting','Running','Needs attention','Done'].map((lane,index)=>`<div class="panel lane"><h2>${lane}</h2><div class="card ${index===2?'amber':index===3?'green':'blue'}"><strong>${index===0?'GitHub discovery':index===1?'Media import':index===2?'URL decision':'Review drafts'}</strong><p class="muted">${index===0?'Count appears after discovery.':index===1?'23% · 12 / 52 items.':index===2?'Confirm first-party domains.':'18 pages ready.'}</p><button class="button secondary" data-action="${index===2?'resolve':'advance'}">${index===2?'Resolve':'Open'}</button></div></div>`).join('')}</section>`;
			break;
	}
	return adminStart(proposal) + body + adminEnd();
}

function index() {
	const frames = proposals.map((proposal, index) => `<section class="proposal-frame"><header><div><h2>${String(index + 1).padStart(2, '0')} ${esc(proposal.title)}</h2><p>${esc(proposal.summary)}</p></div><a href="${proposal.file}" target="_blank" rel="noopener">Open full page</a></header><iframe src="${proposal.file}" loading="lazy" title="${esc(proposal.title)}"></iframe></section>`).join('\n');
	return `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Importer Progress Flow Explorations</title><style>:root{--bg:#f0f0f1;--ink:#1d2327;--muted:#646970;--line:#dcdcde;--panel:#fff;--blue:#3858e9}*{box-sizing:border-box}body{background:var(--bg);color:var(--ink);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:14px;line-height:1.45;margin:0}main{margin:0 auto;max-width:1500px;padding:24px}.hero{align-items:end;display:grid;gap:16px;grid-template-columns:minmax(0,1fr) auto;margin-bottom:16px}h1{font-size:28px;margin:0 0 6px}h2{font-size:17px;margin:0 0 4px}p{color:var(--muted);margin:0}.links{display:flex;flex-wrap:wrap;gap:8px}a{color:var(--blue);font-weight:700;text-decoration:none}.links a,.proposal-frame header a{background:#fff;border:1px solid var(--line);border-radius:999px;padding:7px 10px}.proposal-frame{background:var(--panel);border:1px solid var(--line);border-radius:8px;margin-bottom:18px;overflow:hidden}.proposal-frame header{align-items:center;border-bottom:1px solid var(--line);display:grid;gap:12px;grid-template-columns:minmax(0,1fr) auto;padding:12px 14px}iframe{border:0;display:block;height:760px;width:100%}@media(max-width:760px){main{padding:14px}.hero,.proposal-frame header{display:block}iframe{height:980px}}</style></head><body><main><header class="hero"><div><h1>Importer Progress Flow Explorations</h1><p>20 completely different high-fidelity interactive ideas. Each frame uses its own layout pattern and interaction model.</p></div><nav class="links"><a href="existing-flow-map.html">Flow map</a><a href="flow-critique.md">Critique</a><a href="import-flow-research.md">Research</a></nav></header>${frames}</main></body></html>`;
}

mkdirSync(outDir, { recursive: true });
writeFileSync(join(outDir, 'index.html'), index());

for (const proposal of proposals) {
	writeFileSync(join(outDir, proposal.file), renderProposal(proposal));
}

for (const group of [1, 2, 3, 4]) {
	const groupProposals = proposals.slice((group - 1) * 5, group * 5);
	writeFileSync(join(outDir, `designer-0${group}-notes.md`), '# Designer 0' + group + ' Notes\n\n' + groupProposals.map((proposal, offset) => `${offset + 1}. [${proposal.title}](${proposal.file}) - ${proposal.summary}`).join('\n') + '\n');
}
