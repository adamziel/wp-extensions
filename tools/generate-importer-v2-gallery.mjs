import { mkdirSync, writeFileSync } from "node:fs";
import { join } from "node:path";

const root = "docs/importer/progress-flow-explorations/v2";
const optionDir = join(root, "options");
mkdirSync(optionDir, { recursive: true });

const concepts = [
	["01", "Command Center", "Ops dashboard with queue, current run, blockers, and finish panel.", "command"],
	["02", "Source Inspector", "Source-first parser view with detected content and confidence.", "inspector"],
	["03", "Guided Wizard", "Step-by-step import with a persistent live summary rail.", "wizard"],
	["04", "Spreadsheet Mapper", "Grid-first field mapping for people who think in tables.", "sheet"],
	["05", "Kanban Triage", "Records move through ready, warning, blocked, writing, and done lanes.", "kanban"],
	["06", "Diff Review", "Git-style incoming versus WordPress preview before commit.", "diff"],
	["07", "Media Workbench", "Media-heavy import with downloads, alt text, galleries, and failures.", "media"],
	["08", "Graph Dependencies", "Relationship graph for authors, terms, media, and parent pages.", "graph"],
	["09", "Timeline Runner", "Large event timeline with durable runner status and checkpoints.", "timeline"],
	["10", "Decision Inbox", "Inbox for decisions that need user attention during an import.", "inbox"],
	["11", "Rules Builder", "Transformation rules before the run starts.", "rules"],
	["12", "Content Type Tabs", "Pages, posts, media, users, and terms as first-class setup areas.", "tabs"],
	["13", "Terminal Runbook", "Operator-style runbook with plain language plus technical details.", "terminal"],
	["14", "Visual Source Browser", "Repository and archive browser before selecting what to import.", "browser"],
	["15", "Batch QA Board", "Sample, approve, and flag batches before publishing.", "qa"],
	["16", "Compact Expert Panel", "Dense one-screen controls for repeat imports.", "expert"],
	["17", "Preset Library", "Reusable import recipes with previewable defaults.", "presets"],
	["18", "Import Health Check", "Site Health style readiness checks before the run.", "health"],
	["19", "Relationship Resolver", "Focused resolver for missing authors, terms, and links.", "resolver"],
	["20", "Minimal One-Screen", "Smallest complete importer surface for a first-run user.", "minimal"],
];

const palettes = {
	command: ["#172033", "#3858e9", "#edf2ff"],
	inspector: ["#16302b", "#008a20", "#edf8ef"],
	wizard: ["#2b2540", "#674399", "#f4f0ff"],
	sheet: ["#203029", "#067a46", "#edf8f2"],
	kanban: ["#2f2516", "#996800", "#fcf9e8"],
	diff: ["#1e2a38", "#2271b1", "#eef6fc"],
	media: ["#302033", "#b04c8c", "#fff0f8"],
	graph: ["#17262b", "#007c89", "#e8f7f9"],
	timeline: ["#2b2730", "#7a5a00", "#fff8dc"],
	inbox: ["#2d1f1f", "#b32d2e", "#fcf0f1"],
	rules: ["#212b24", "#168257", "#eef8f2"],
	tabs: ["#1d2735", "#315efb", "#eef3ff"],
	terminal: ["#111827", "#22c55e", "#ecfdf5"],
	browser: ["#102a43", "#0ea5e9", "#e0f2fe"],
	qa: ["#2b2430", "#8b5cf6", "#f5f3ff"],
	expert: ["#202124", "#2271b1", "#f6f7f7"],
	presets: ["#2b2416", "#c27d10", "#fff7e6"],
	health: ["#18261d", "#008a20", "#edf8ef"],
	resolver: ["#241d32", "#7c3aed", "#f3e8ff"],
	minimal: ["#1f2937", "#111827", "#f3f4f6"],
};

function esc(value) {
	return String(value).replace(/[&<>"']/g, (char) => ({
		"&": "&amp;",
		"<": "&lt;",
		">": "&gt;",
		'"': "&quot;",
		"'": "&#39;",
	}[char]));
}

function featureButtons(kind) {
	const labels = {
		command: ["Queue", "Blockers", "Results"],
		inspector: ["Detect", "Confidence", "Preview"],
		wizard: ["Source", "Mapping", "Review"],
		sheet: ["Columns", "Rules", "Conflicts"],
		kanban: ["Ready", "Warnings", "Done"],
		diff: ["Changed", "New", "Skipped"],
		media: ["Downloads", "Alt text", "Failures"],
		graph: ["Authors", "Terms", "Media"],
		timeline: ["Events", "Checkpoints", "Retry"],
		inbox: ["Needs action", "Resolved", "Muted"],
		rules: ["Slug rules", "Defaults", "Conditions"],
		tabs: ["Pages", "Posts", "Media"],
		terminal: ["Plan", "Logs", "Recovery"],
		browser: ["Tree", "Selection", "Parsed"],
		qa: ["Sample", "Approve", "Flag"],
		expert: ["Options", "URLs", "Run"],
		presets: ["Recipes", "Defaults", "History"],
		health: ["Ready", "Warnings", "Limits"],
		resolver: ["Authors", "Parents", "Links"],
		minimal: ["Source", "Options", "Finish"],
	}[kind] || ["Detect", "Map", "Review"];
	return labels.map((label, index) => `<button class="feature ${index === 0 ? "active" : ""}" data-feature="${esc(label)}">${esc(label)}</button>`).join("");
}

function bodyFor(kind, title) {
	const source = `
		<section class="source-panel">
			<div class="eyebrow">Tools / Universal Importer</div>
			<h1>${esc(title)}</h1>
			<p class="lead">Start with a source. The importer inspects it before writing anything to WordPress.</p>
			<div class="source-row">
				<input value="https://github.com/WordPress/gutenberg/tree/trunk/docs/explanations/architecture" aria-label="Import source">
				<button class="ghost">Browse</button>
			</div>
			<div class="chips">${featureButtons(kind)}</div>
			<button class="primary start">Review import</button>
		</section>`;

	const progress = `
		<section class="run-panel">
			<div class="run-head"><strong class="status">Ready</strong><span class="count">0 / 42</span></div>
			<div class="bar"><span></span></div>
			<ol class="log"><li>Waiting for source review.</li></ol>
			<div class="result">Review result will appear after the run.</div>
		</section>`;

	const blocks = {
		command: `<section class="command-grid">${source}<div class="metric"><b>42</b><span>candidate files</span></div><div class="metric warn"><b>3</b><span>decisions</span></div>${progress}</section>`,
		inspector: `<section class="split">${source}<aside class="inspection"><h2>Detected structure</h2><div class="tree">docs/explanations/architecture<br>├ index.md<br>├ data-flow.md<br>└ packages.md</div><div class="confidence">92% source confidence</div></aside>${progress}</section>`,
		wizard: `<section class="wizard"><nav><b>1 Source</b><span>2 Map</span><span>3 URLs</span><span>4 Run</span></nav><div>${source}${progress}</div><aside><h2>Live summary</h2><p>Pages as drafts, ask about old URLs, import media.</p></aside></section>`,
		sheet: `<section class="sheet"><div>${source}</div><table><thead><tr><th>Source</th><th>WordPress</th><th>Sample</th></tr></thead><tbody><tr><td>title</td><td>post_title</td><td>Architecture</td></tr><tr><td>body</td><td>post_content</td><td>Markdown</td></tr><tr><td>path</td><td>post_name</td><td>architecture</td></tr></tbody></table>${progress}</section>`,
		kanban: `<section class="kanban">${source}<div class="lanes"><div><h2>Ready</h2><p>31 pages</p></div><div><h2>Warnings</h2><p>5 old URLs</p></div><div><h2>Blocked</h2><p>1 author</p></div><div><h2>Done</h2><p>0 written</p></div></div>${progress}</section>`,
		diff: `<section class="diff">${source}<div class="compare"><pre>- Old permalink\n+ New WordPress page\n+ Imported media reference</pre><pre>Preview\nTitle: Architecture\nStatus: Draft\nAuthor: Editorial Desk</pre></div>${progress}</section>`,
		media: `<section class="media">${source}<div class="media-grid"><div>hero.png<br><small>queued</small></div><div>diagram.svg<br><small>reuse existing</small></div><div>photo.jpg<br><small>needs alt text</small></div></div>${progress}</section>`,
		graph: `<section class="graph">${source}<div class="nodes"><span>Docs</span><span>Authors</span><span>Terms</span><span>Media</span><span>Pages</span></div>${progress}</section>`,
		timeline: `<section class="timeline">${source}<div class="rail"><b>Inspect</b><b>Prepare</b><b>URLs</b><b>Media</b><b>Write</b></div>${progress}</section>`,
		inbox: `<section class="inbox">${source}<div class="messages"><p><b>URL decision</b> Rewrite github.com links?</p><p><b>Author match</b> Map contributor to admin?</p><p><b>Media</b> Continue if two files fail?</p></div>${progress}</section>`,
		rules: `<section class="rules">${source}<div class="rule-list"><p>If path contains /architecture/ -> parent page Architecture</p><p>Slug from filename, lowercase</p><p>External images -> media library</p></div>${progress}</section>`,
		tabs: `<section class="tabs">${source}<div class="type-tabs"><button>Pages 31</button><button>Media 18</button><button>Terms 7</button><button>Users 2</button></div>${progress}</section>`,
		terminal: `<section class="terminal">${source}<pre>$ importer plan\nread github tree\nprepare markdown\npause for URL decision\nwrite drafts</pre>${progress}</section>`,
		browser: `<section class="browser">${source}<div class="file-browser"><aside>gutenberg<br>docs<br>explanations<br><b>architecture</b></aside><main>index.md<br>block-editor.md<br>data-flow.md</main></div>${progress}</section>`,
		qa: `<section class="qa">${source}<div class="samples"><article>Sample 1 approved</article><article>Sample 2 has warning</article><article>Sample 3 pending</article></div>${progress}</section>`,
		expert: `<section class="expert">${source}<div class="switches"><label><input type="checkbox" checked> Import as drafts</label><label><input type="checkbox" checked> Download media</label><label><input type="checkbox"> Dry run only</label></div>${progress}</section>`,
		presets: `<section class="presets">${source}<div class="recipes"><button>Docs site</button><button>Newsroom WXR</button><button>Product CSV</button></div>${progress}</section>`,
		health: `<section class="health">${source}<div class="checks"><p>Pass: permissions</p><p>Pass: disk space</p><p>Warn: remote media timeout</p></div>${progress}</section>`,
		resolver: `<section class="resolver">${source}<div class="resolve"><p>adamziel -> admin</p><p>/docs -> Documentation parent</p><p>old.example.com -> current site</p></div>${progress}</section>`,
		minimal: `<section class="minimal">${source}${progress}</section>`,
	};
	return blocks[kind] || `${source}${progress}`;
}

function optionHtml([id, title, desc, kind]) {
	const [dark, accent, soft] = palettes[kind];
	return `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${esc(id)} ${esc(title)}</title>
<style>
:root{--dark:${dark};--accent:${accent};--soft:${soft};--bg:#f0f0f1;--panel:#fff;--line:#dcdcde;--text:#1d2327;--muted:#646970}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}button,input{font:inherit}button{cursor:pointer}
.topbar{height:36px;background:#1d2327;color:#f0f0f1;display:flex;align-items:center;gap:18px;padding:0 16px}.topbar span{color:#c3c4c7}.wrap{padding:22px}.option-title{display:flex;align-items:end;justify-content:space-between;gap:16px;margin-bottom:16px}.option-title h1{font-size:24px;margin:0}.option-title p{margin:4px 0 0;color:var(--muted)}.badge{background:var(--soft);color:var(--dark);border:1px solid color-mix(in srgb,var(--accent),#fff 55%);border-radius:999px;padding:6px 10px;font-weight:700}
section{gap:14px}.source-panel,.run-panel,.inspection,.metric,.lanes>div,.compare pre,.media-grid>div,.messages,.rule-list,.type-tabs,.file-browser,.samples article,.switches,.recipes,.checks,.resolve,aside{background:var(--panel);border:1px solid var(--line);border-radius:8px;box-shadow:0 1px 2px rgba(0,0,0,.05);padding:14px}.eyebrow{color:var(--accent);font-size:12px;font-weight:700;text-transform:uppercase}.lead,.result,.log,.count{color:var(--muted)}.source-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;margin:12px 0}.source-row input{border:1px solid var(--line);border-radius:4px;min-height:36px;padding:7px 9px}.primary,.ghost,.feature,.recipes button,.type-tabs button{border:1px solid var(--accent);border-radius:4px;min-height:34px;padding:7px 11px}.primary{background:var(--accent);color:white;font-weight:700}.ghost,.feature,.recipes button,.type-tabs button{background:white;color:var(--accent)}.feature.active{background:var(--dark);border-color:var(--dark);color:white}.chips{display:flex;flex-wrap:wrap;gap:8px;margin:10px 0}.run-head{display:flex;justify-content:space-between;gap:12px}.bar{height:12px;background:#dcdcde;border-radius:999px;overflow:hidden;margin:10px 0}.bar span{display:block;height:100%;width:0;background:linear-gradient(90deg,var(--accent),#008a20);transition:width .25s}.log{max-height:120px;overflow:auto;padding-left:18px}.command-grid{display:grid;grid-template-columns:1.1fr .45fr .45fr 1fr}.metric b{font-size:38px}.warn{border-left:4px solid #996800}.split,.wizard,.sheet,.kanban,.diff,.media,.graph,.timeline,.inbox,.rules,.tabs,.terminal,.browser,.qa,.expert,.presets,.health,.resolver{display:grid;grid-template-columns:1fr 1fr;align-items:start}.wizard{grid-template-columns:190px 1fr 280px}.wizard nav{background:var(--dark);color:white;border-radius:8px;padding:14px;display:grid;gap:12px}.sheet table{background:white;border-collapse:collapse;width:100%;border:1px solid var(--line)}th,td{border-bottom:1px solid var(--line);padding:10px;text-align:left}.lanes,.media-grid,.nodes,.samples,.recipes,.checks,.resolve{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}.nodes span{background:var(--soft);border:2px solid var(--accent);border-radius:999px;padding:16px;text-align:center}.rail{display:grid;grid-template-columns:repeat(5,1fr);gap:8px}.rail b{background:white;border-top:4px solid var(--accent);border-radius:6px;padding:14px}.terminal pre{background:#0b1020;color:#d1fae5;border-radius:8px;padding:18px;min-height:210px}.file-browser{display:grid;grid-template-columns:180px 1fr;gap:12px}.expert,.minimal{max-width:1180px;margin:auto}.minimal{grid-template-columns:1fr 1fr}
@media(max-width:900px){.command-grid,.split,.wizard,.sheet,.kanban,.diff,.media,.graph,.timeline,.inbox,.rules,.tabs,.terminal,.browser,.qa,.expert,.presets,.health,.resolver,.minimal{grid-template-columns:1fr}.lanes,.media-grid,.nodes,.samples,.recipes,.checks,.resolve,.rail{grid-template-columns:1fr}.wrap{padding:14px}}
</style>
</head>
<body>
<div class="topbar"><strong>WordPress</strong><span>Tools</span><span>Universal Importer</span><span>${esc(title)}</span></div>
<main class="wrap">
	<header class="option-title"><div><h1>${esc(id)} ${esc(title)}</h1><p>${esc(desc)}</p></div><span class="badge">Clickable proposal</span></header>
	${bodyFor(kind, title)}
</main>
<script>
const phases=["queued","fetching repository files","preparing content","URL treatment","importing media","writing pages","complete"];
document.querySelectorAll(".feature").forEach((button)=>button.addEventListener("click",()=>{document.querySelectorAll(".feature").forEach((item)=>item.classList.remove("active"));button.classList.add("active");document.querySelector(".result").textContent=button.dataset.feature+" controls are now previewed for this importer concept.";}));
document.querySelectorAll(".start").forEach((button)=>button.addEventListener("click",(event)=>{event.preventDefault();let i=0;const status=document.querySelector(".status");const bar=document.querySelector(".bar span");const count=document.querySelector(".count");const log=document.querySelector(".log");const result=document.querySelector(".result");button.disabled=true;log.innerHTML="";const timer=setInterval(()=>{const phase=phases[i];const pct=Math.round((i/(phases.length-1))*100);status.textContent=phase;bar.style.width=pct+"%";count.textContent=Math.min(42,Math.round(pct*.42))+" / 42";const li=document.createElement("li");li.textContent=phase;log.appendChild(li);if(phase==="complete"){clearInterval(timer);button.disabled=false;result.textContent="Complete: 31 draft pages, 18 media items, 3 URL decisions saved."; }i++;},650);}));
</script>
</body>
</html>`;
}

function indexHtml() {
	const cards = concepts.map(([id, title, desc]) => `
		<section class="proposal" id="proposal-${id}">
			<header><div><h2>${esc(id)} ${esc(title)}</h2><p>${esc(desc)}</p></div><a href="options/option-${id}.html" target="_blank" rel="noopener">Open full page</a></header>
			<iframe src="options/option-${id}.html" title="${esc(id)} ${esc(title)}"></iframe>
		</section>`).join("\n");
	const nav = concepts.map(([id, title]) => `<a href="#proposal-${id}">${id} ${esc(title)}</a>`).join("");
	return `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Importer Progress Flow Explorations v2</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f0f0f1;color:#1d2327;font:14px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}a{color:#2271b1;text-decoration:none}main{padding:20px;max-width:1800px;margin:auto}.hero{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:20px;align-items:end;margin-bottom:16px}.hero h1{font-size:30px;margin:0 0 6px}.hero p{color:#646970;margin:0}.nav{position:sticky;top:0;z-index:2;background:#f0f0f1;border-bottom:1px solid #dcdcde;padding:10px 0;display:flex;gap:8px;overflow:auto}.nav a,.proposal header a{background:white;border:1px solid #dcdcde;border-radius:999px;padding:7px 10px;white-space:nowrap}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-top:16px}.proposal{background:white;border:1px solid #dcdcde;border-radius:8px;overflow:hidden;box-shadow:0 1px 2px rgba(0,0,0,.05)}.proposal header{min-height:74px;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;align-items:center;border-bottom:1px solid #dcdcde;padding:12px 14px}.proposal h2{font-size:17px;margin:0 0 3px}.proposal p{margin:0;color:#646970}iframe{display:block;width:100%;height:620px;border:0;background:white}@media(max-width:1100px){.grid{grid-template-columns:1fr}.hero{display:block}iframe{height:720px}}@media(max-width:700px){main{padding:12px}.proposal header{display:block}iframe{height:820px}}
</style>
</head>
<body>
<main>
	<header class="hero"><div><h1>Universal Importer: 20 Different First-Screen Journeys</h1><p>Each proposal is a separate high-fidelity clickable prototype. Click feature controls, then click the primary import button inside any frame to experience the run.</p></div><strong>v2 gallery</strong></header>
	<nav class="nav" aria-label="Proposal jump links">${nav}</nav>
	<div class="grid">${cards}</div>
</main>
</body>
</html>`;
}

for (const concept of concepts) {
	writeFileSync(join(optionDir, `option-${concept[0]}.html`), optionHtml(concept));
}
writeFileSync(join(root, "index.html"), indexHtml());
writeFileSync(join(root, "qa-redo-notes.md"), `# QA redo notes\n\nGenerated 20 self-contained clickable importer proposal pages plus the v2 iframe gallery.\n\nVerification should confirm that index.html renders styled gallery content, all 20 option pages exist, feature buttons change visible review copy, and the primary import button advances progress to completion.\n`);
