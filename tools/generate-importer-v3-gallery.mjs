import { mkdirSync, writeFileSync } from "node:fs";
import { join } from "node:path";

const root = "docs/importer-progress-flow-explorations/v3";
const optionDir = join(root, "options");
mkdirSync(optionDir, { recursive: true });

const concepts = [
	{ n: 21, title: "Radial Decision Map", mode: "radial", tone: ["#1f2437", "#e05a47", "#fff1ed"], desc: "Start from the source, then fan out URL, media, author, and conflict decisions." },
	{ n: 22, title: "Command Palette Importer", mode: "palette", tone: ["#101828", "#7c3aed", "#f5f3ff"], desc: "A keyboard-first source picker with discoverable commands and staged run output." },
	{ n: 23, title: "Source Calendar", mode: "calendar", tone: ["#17324d", "#0ea5e9", "#e0f2fe"], desc: "Scheduled and dated sources first, useful for recurring feed and archive imports." },
	{ n: 24, title: "Split-Pane IDE", mode: "ide", tone: ["#111827", "#22c55e", "#ecfdf5"], desc: "Repository tree, parsed markdown preview, and WordPress destination side by side." },
	{ n: 25, title: "Import Storyboard", mode: "storyboard", tone: ["#2b1d35", "#db2777", "#fdf2f8"], desc: "A narrative sequence of source, transformation, decision, and result frames." },
	{ n: 26, title: "Checklist Launchpad", mode: "checklist", tone: ["#22311d", "#65a30d", "#f7fee7"], desc: "A readiness checklist drives the first import without hiding advanced controls." },
	{ n: 27, title: "Dependency Matrix", mode: "matrix", tone: ["#312e1d", "#ca8a04", "#fefce8"], desc: "Rows and columns expose which pages depend on authors, media, URLs, and terms." },
	{ n: 28, title: "Browser Folder Tree", mode: "tree", tone: ["#172554", "#2563eb", "#eff6ff"], desc: "A file-browser workflow for selecting folders before starting the importer." },
	{ n: 29, title: "URL Policy Lab", mode: "policy", tone: ["#3b1f1f", "#dc2626", "#fef2f2"], desc: "Old-domain rules are tested against examples before the run starts." },
	{ n: 30, title: "Run Comparison Table", mode: "compare", tone: ["#1f2937", "#0891b2", "#ecfeff"], desc: "Compare dry run, draft import, and publish modes before choosing one." },
	{ n: 31, title: "Queue Heatmap", mode: "heatmap", tone: ["#2d2338", "#9333ea", "#faf5ff"], desc: "Large imports surface source density, failures, and hot spots as a heatmap." },
	{ n: 32, title: "Mini Site Map", mode: "sitemap", tone: ["#12332b", "#059669", "#ecfdf5"], desc: "The destination site structure is previewed before pages are written." },
	{ n: 33, title: "Package Tracker", mode: "package", tone: ["#352414", "#d97706", "#fff7ed"], desc: "Import stages are tracked like packages moving through scanners and hubs." },
	{ n: 34, title: "Accessibility Review", mode: "access", tone: ["#1e293b", "#4f46e5", "#eef2ff"], desc: "Alt text, headings, links, and document structure become visible first-run checks." },
	{ n: 35, title: "Conflict Courtroom", mode: "court", tone: ["#302018", "#b45309", "#fffbeb"], desc: "Conflicting records are argued with evidence, suggestions, and a clear verdict." },
	{ n: 36, title: "Media Contact Sheet", mode: "contact", tone: ["#3b1230", "#be185d", "#fdf2f8"], desc: "Image-heavy sources start from a contact sheet and media import health." },
	{ n: 37, title: "Migration Cockpit", mode: "cockpit", tone: ["#111827", "#06b6d4", "#cffafe"], desc: "Dense operational controls for source, runner, decisions, and destination telemetry." },
	{ n: 38, title: "Plain-Language Assistant", mode: "assistant", tone: ["#1f2a24", "#16a34a", "#f0fdf4"], desc: "A conversational first screen explains choices without hiding direct controls." },
	{ n: 39, title: "Audit Ledger", mode: "ledger", tone: ["#202124", "#64748b", "#f8fafc"], desc: "Every importer action is represented as a durable ledger entry before commit." },
	{ n: 40, title: "Single-Line Power Command", mode: "command", tone: ["#0f172a", "#f97316", "#fff7ed"], desc: "One command line expands into parsed source, options, and live run status." },
];

function pad(n) {
	return String(n).padStart(2, "0");
}

function esc(value) {
	return String(value).replace(/[&<>"']/g, (char) => ({
		"&": "&amp;",
		"<": "&lt;",
		">": "&gt;",
		'"': "&quot;",
		"'": "&#39;",
	}[char]));
}

function shell(concept, body) {
	const [dark, accent, soft] = concept.tone;
	return `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${pad(concept.n)} ${esc(concept.title)}</title>
<style>
:root{--dark:${dark};--accent:${accent};--soft:${soft};--bg:#f0f0f1;--panel:#fff;--line:#dcdcde;--ink:#1d2327;--muted:#646970}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:14px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}button,input,select{font:inherit}button{cursor:pointer}h1,h2,h3,p{margin-top:0}h1{font-size:24px}h2{font-size:16px}input,select{border:1px solid var(--line);border-radius:4px;min-height:36px;padding:7px 9px;width:100%}.bar{height:10px;background:#dcdcde;border-radius:999px;overflow:hidden}.bar span{display:block;height:100%;width:0;background:linear-gradient(90deg,var(--accent),#008a20);transition:width .25s}.btn{border:1px solid var(--accent);border-radius:4px;background:#fff;color:var(--accent);min-height:34px;padding:7px 11px}.primary{background:var(--accent);color:#fff;font-weight:700}.top{background:var(--dark);color:#fff;display:flex;gap:16px;align-items:center;min-height:38px;padding:0 16px}.top span{opacity:.75}.wrap{padding:20px}.tag{display:inline-flex;border:1px solid color-mix(in srgb,var(--accent),#fff 55%);background:var(--soft);color:var(--dark);border-radius:999px;padding:5px 9px;font-weight:700}.source{background:#fff;border:1px solid var(--line);border-radius:8px;padding:14px;box-shadow:0 1px 2px rgba(0,0,0,.05)}.source h1{margin-bottom:6px}.source p,.muted{color:var(--muted)}.source-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;margin:12px 0}.features{display:flex;flex-wrap:wrap;gap:8px;margin:10px 0}.feature.active{background:var(--dark);border-color:var(--dark);color:#fff}.run{background:#fff;border:1px solid var(--line);border-radius:8px;padding:14px}.run-head{display:flex;justify-content:space-between;gap:12px}.log{color:var(--muted);max-height:112px;overflow:auto;padding-left:18px}.result{border-left:4px solid var(--accent);background:var(--soft);padding:10px;margin-top:10px}.hero-title{display:flex;justify-content:space-between;gap:12px;align-items:end;margin-bottom:14px}.hero-title h1{margin:0}.hero-title p{margin:4px 0 0;color:var(--muted)}
${modeCss(concept.mode)}
@media(max-width:900px){.wrap{padding:14px}.layout,.split,.grid,.wide,.three,.matrix,.cockpit,.ledger,.assistant,.tree-layout,.calendar,.compare,.contact,.court,.sitemap,.package,.policy,.heatmap,.storyboard,.checklist{grid-template-columns:1fr!important}.radial-map{min-height:auto}.radial-node{position:static;margin:8px}.top{overflow:auto}}
</style>
</head>
<body>
<div class="top"><strong>WordPress</strong><span>Tools</span><span>Universal Importer</span><span>${esc(concept.title)}</span></div>
<main class="wrap">
<header class="hero-title"><div><h1>${pad(concept.n)} ${esc(concept.title)}</h1><p>${esc(concept.desc)}</p></div><span class="tag">new v3 proposal</span></header>
${body}
</main>
<script>
const phases=["queued","reading source","preparing content","URL treatment","importing media","writing pages","complete"];
function qs(sel){return document.querySelector(sel)}
document.querySelectorAll(".feature").forEach((button)=>button.addEventListener("click",()=>{document.querySelectorAll(".feature").forEach((item)=>item.classList.remove("active"));button.classList.add("active");const target=qs(".feature-output");if(target)target.textContent=button.dataset.copy||button.textContent+" is now previewed.";const panel=qs("[data-dynamic-panel]");if(panel)panel.dataset.mode=button.textContent.toLowerCase().replace(/\\\\s+/g,"-");}));
document.querySelectorAll(".start").forEach((button)=>button.addEventListener("click",(event)=>{event.preventDefault();let i=0;button.disabled=true;const status=qs(".status");const count=qs(".count");const fill=qs(".bar span");const log=qs(".log");const result=qs(".result");if(log)log.innerHTML="";const timer=setInterval(()=>{const phase=phases[i];const pct=Math.round((i/(phases.length-1))*100);if(status)status.textContent=phase;if(count)count.textContent=Math.min(64,Math.round(pct*.64))+" / 64";if(fill)fill.style.width=pct+"%";if(log){const li=document.createElement("li");li.textContent=phase;log.appendChild(li)}if(phase==="complete"){clearInterval(timer);button.disabled=false;if(result)result.textContent="Complete: 38 draft pages, 22 media items, 4 URL decisions, 2 conflicts resolved."; }i++;},560);}));
</script>
</body>
</html>`;
}

function firstScreen(concept, controls = "Inspect URLs Media Review") {
	return `<section class="source">
		<div class="tag">First screen</div>
		<h1>${esc(concept.title)}</h1>
		<p>Choose a source, inspect what WordPress will create, then run with visible checkpoints.</p>
		<div class="source-row"><input value="https://github.com/WordPress/gutenberg/tree/trunk/docs/explanations/architecture"><button class="btn">Browse</button></div>
		<div class="features">${controls.split(" ").map((label, index) => `<button class="btn feature ${index === 0 ? "active" : ""}" data-copy="${esc(label)} view changes the preview and available decisions.">${esc(label)}</button>`).join("")}</div>
		<button class="btn primary start">Review import</button>
	</section>`;
}

function runPanel() {
	return `<section class="run"><div class="run-head"><strong class="status">Ready</strong><span class="count">0 / 64</span></div><div class="bar"><span></span></div><ol class="log"><li>Waiting for source review.</li></ol><div class="result">Run result appears here.</div></section>`;
}

function modeCss(mode) {
	return {
		radial: ".radial{display:grid;grid-template-columns:360px 1fr;gap:16px}.radial-map{position:relative;min-height:430px;background:#fff;border:1px solid var(--line);border-radius:50%;padding:24px}.radial-node{position:absolute;background:var(--soft);border:2px solid var(--accent);border-radius:999px;padding:12px}.radial-node:nth-child(1){left:42%;top:42%}.radial-node:nth-child(2){left:8%;top:20%}.radial-node:nth-child(3){right:8%;top:18%}.radial-node:nth-child(4){left:14%;bottom:18%}.radial-node:nth-child(5){right:12%;bottom:16%}",
		palette: ".palette{display:grid;grid-template-columns:1fr 380px;gap:16px}.command-box{background:#111827;color:#e5e7eb;border-radius:10px;padding:16px}.command-box input{background:#020617;color:#fff;border-color:#334155}.command-list{display:grid;gap:8px;margin-top:12px}.command-list button{text-align:left;background:#1f2937;color:#fff;border:1px solid #374151;border-radius:6px;padding:10px}",
		calendar: ".calendar{display:grid;grid-template-columns:1fr 390px;gap:16px}.month{background:#fff;border:1px solid var(--line);border-radius:8px;padding:12px;display:grid;grid-template-columns:repeat(7,1fr);gap:6px}.day{min-height:62px;border:1px solid var(--line);border-radius:6px;padding:6px}.day.hot{background:var(--soft);border-color:var(--accent)}",
		ide: ".split{display:grid;grid-template-columns:260px 1fr 360px;gap:12px}.pane{background:#0b1020;color:#d1d5db;border-radius:8px;padding:14px;min-height:360px}.preview{background:#fff;border:1px solid var(--line);border-radius:8px;padding:14px}",
		storyboard: ".storyboard{display:grid;gap:12px}.frames{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.frame{background:#fff;border:1px solid var(--line);border-radius:10px;padding:14px;min-height:180px}.frame b{display:block;font-size:28px;color:var(--accent)}",
		checklist: ".checklist{display:grid;grid-template-columns:1fr 420px;gap:16px}.checks{background:#fff;border:1px solid var(--line);border-radius:8px;padding:14px}.checks label{display:flex;gap:8px;padding:9px;border-bottom:1px solid var(--line)}",
		matrix: ".matrix{display:grid;grid-template-columns:1fr 380px;gap:16px}.matrix-table{background:#fff;border:1px solid var(--line);border-radius:8px;overflow:hidden;display:grid;grid-template-columns:1.2fr repeat(4,1fr)}.matrix-table div{padding:10px;border-bottom:1px solid var(--line);border-right:1px solid var(--line)}",
		tree: ".tree-layout{display:grid;grid-template-columns:320px 1fr;gap:16px}.tree{background:#fff;border:1px solid var(--line);border-radius:8px;padding:14px;font-family:ui-monospace,monospace}.file-card{background:var(--soft);border:1px solid var(--accent);border-radius:6px;padding:9px;margin:8px 0}",
		policy: ".policy{display:grid;grid-template-columns:1fr 1fr;gap:16px}.lab{background:#fff;border:1px solid var(--line);border-radius:8px;padding:14px}.url-example{font-family:ui-monospace,monospace;background:var(--soft);padding:10px;border-radius:4px;margin:8px 0}",
		compare: ".compare{display:grid;grid-template-columns:1fr 420px;gap:16px}.plans{background:#fff;border:1px solid var(--line);border-radius:8px;overflow:hidden}.plan{display:grid;grid-template-columns:1fr repeat(3,120px);border-bottom:1px solid var(--line)}.plan>*{padding:10px;border-right:1px solid var(--line)}",
		heatmap: ".heatmap{display:grid;grid-template-columns:1fr 360px;gap:16px}.heat{background:#fff;border:1px solid var(--line);border-radius:8px;padding:12px;display:grid;grid-template-columns:repeat(12,1fr);gap:5px}.cell{aspect-ratio:1;border-radius:4px;background:color-mix(in srgb,var(--accent),#fff 70%)}.cell.hot{background:var(--accent)}",
		sitemap: ".sitemap{display:grid;grid-template-columns:1fr 380px;gap:16px}.map{background:#fff;border:1px solid var(--line);border-radius:8px;padding:20px}.map ul{border-left:2px solid var(--accent);margin-left:12px}",
		package: ".package{display:grid;grid-template-columns:1fr 400px;gap:16px}.track{display:grid;grid-template-columns:repeat(5,1fr);gap:8px}.scan{background:#fff;border:1px solid var(--line);border-top:5px solid var(--accent);border-radius:8px;padding:12px;min-height:150px}",
		access: ".wide{display:grid;grid-template-columns:1fr 1fr;gap:16px}.audit{background:#fff;border:1px solid var(--line);border-radius:8px;padding:14px}.score{font-size:58px;color:var(--accent);font-weight:800}",
		court: ".court{display:grid;grid-template-columns:1fr 360px;gap:16px}.bench{background:#fff;border:1px solid var(--line);border-radius:10px;padding:18px}.evidence{display:grid;gap:10px}.evidence p{background:var(--soft);border-left:4px solid var(--accent);padding:10px}",
		contact: ".contact{display:grid;grid-template-columns:1fr 360px;gap:16px}.sheet{background:#fff;border:1px solid var(--line);border-radius:8px;padding:12px;display:grid;grid-template-columns:repeat(5,1fr);gap:10px}.thumb{aspect-ratio:1;background:linear-gradient(135deg,var(--soft),#fff);border:1px solid var(--line);border-radius:6px;padding:8px}",
		cockpit: ".cockpit{display:grid;grid-template-columns:300px 1fr 340px;gap:14px}.dial{background:#fff;border:12px solid var(--soft);border-top-color:var(--accent);border-radius:50%;height:190px;width:190px;display:grid;place-items:center;font-size:42px;font-weight:800}.telemetry{background:#fff;border:1px solid var(--line);border-radius:8px;padding:14px}",
		assistant: ".assistant{display:grid;grid-template-columns:1fr 420px;gap:16px}.chat{background:#fff;border:1px solid var(--line);border-radius:12px;padding:14px}.bubble{max-width:80%;background:var(--soft);padding:10px;border-radius:12px;margin:8px 0}.bubble.user{margin-left:auto;background:#fff;border:1px solid var(--line)}",
		ledger: ".ledger{display:grid;grid-template-columns:1fr 380px;gap:16px}.entries{background:#fff;border:1px solid var(--line);border-radius:8px;overflow:hidden}.entry{display:grid;grid-template-columns:120px 1fr 100px;gap:10px;padding:10px;border-bottom:1px solid var(--line)}",
		command: ".single{display:grid;grid-template-columns:1fr;gap:16px}.line{background:#0f172a;color:#fff;border-radius:10px;padding:16px;font:16px/1.5 ui-monospace,monospace}.expanded{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.expanded div{background:#fff;border:1px solid var(--line);border-radius:8px;padding:14px}",
	}[mode] || "";
}

function layout(concept) {
	const source = firstScreen(concept, controlsFor(concept.mode));
	const run = runPanel();
	const output = `<section class="run"><h2>Feature preview</h2><p class="feature-output">Choose a feature control to change this preview.</p><div data-dynamic-panel></div></section>`;
	switch (concept.mode) {
		case "radial": return `<div class="radial">${source}<div class="radial-map"><span class="radial-node">Source</span><span class="radial-node">URLs</span><span class="radial-node">Media</span><span class="radial-node">Authors</span><span class="radial-node">Conflicts</span></div>${run}</div>`;
		case "palette": return `<div class="palette"><div>${source}<section class="command-box"><input value="import github docs as drafts"><div class="command-list"><button>Detect source type</button><button>Open URL policy</button><button>Run dry import</button></div></section></div>${run}</div>`;
		case "calendar": return `<div class="calendar"><div>${source}<section class="month">${Array.from({ length: 35 }, (_, i) => `<div class="day ${[5, 12, 18, 26].includes(i) ? "hot" : ""}">${i + 1}<br><small>${[5, 12, 18, 26].includes(i) ? "source" : ""}</small></div>`).join("")}</section></div>${run}</div>`;
		case "ide": return `<div class="split"><section class="pane">repo<br> docs<br>  explanations<br>   architecture<br><br>index.md<br>data-flow.md</section><div>${source}<section class="preview"><h2>Parsed preview</h2><p>31 Markdown documents, 18 media references, 5 old URLs.</p></section></div>${run}</div>`;
		case "storyboard": return `<div class="storyboard">${source}<section class="frames"><div class="frame"><b>1</b>Source</div><div class="frame"><b>2</b>Transform</div><div class="frame"><b>3</b>Decide</div><div class="frame"><b>4</b>Review</div></section>${run}</div>`;
		case "checklist": return `<div class="checklist"><div>${source}<section class="checks"><label><input type="checkbox" checked> Source reachable</label><label><input type="checkbox" checked> Import as drafts</label><label><input type="checkbox"> Rewrite old URLs</label></section></div>${run}</div>`;
		case "matrix": return `<div class="matrix"><div>${source}<section class="matrix-table">${["Page","Author","Media","Terms","URLs","Intro","ok","18","7","5","API","ok","0","3","1","Blocks","needs","4","2","0"].map((x)=>`<div>${x}</div>`).join("")}</section></div>${run}</div>`;
		case "tree": return `<div class="tree-layout"><section class="tree">gutenberg/<br><span class="file-card">docs/</span><span class="file-card">explanations/</span><span class="file-card">architecture/</span></section><div>${source}${run}</div></div>`;
		case "policy": return `<div class="policy"><div>${source}<section class="lab"><h2>URL policy examples</h2><p class="url-example">old.example.com/docs -> /docs</p><p class="url-example">github.com/... -> preserve</p></section></div>${run}</div>`;
		case "compare": return `<div class="compare"><div>${source}<section class="plans"><div class="plan"><b>Mode</b><b>Writes</b><b>Media</b><b>Risk</b></div><div class="plan"><span>Dry run</span><span>No</span><span>Scan</span><span>Low</span></div><div class="plan"><span>Drafts</span><span>Yes</span><span>Yes</span><span>Med</span></div></section></div>${run}</div>`;
		case "heatmap": return `<div class="heatmap"><div>${source}<section class="heat">${Array.from({ length: 96 }, (_, i) => `<span class="cell ${i % 7 === 0 || i % 19 === 0 ? "hot" : ""}"></span>`).join("")}</section></div>${run}</div>`;
		case "sitemap": return `<div class="sitemap"><div>${source}<section class="map"><ul><li>Home<ul><li>Docs<ul><li>Architecture</li><li>Packages</li></ul></li><li>Reference</li></ul></li></ul></section></div>${run}</div>`;
		case "package": return `<div class="package"><div>${source}<section class="track"><div class="scan">Accepted</div><div class="scan">Scanned</div><div class="scan">Packed</div><div class="scan">Delivered</div><div class="scan">Reviewed</div></section></div>${run}</div>`;
		case "access": return `<div class="wide"><div>${source}<section class="audit"><div class="score">87</div><p>Heading order, alt text, and link text are checked before writing.</p></section></div>${run}</div>`;
		case "court": return `<div class="court"><div>${source}<section class="bench"><h2>Conflict hearing</h2><div class="evidence"><p>Duplicate slug: architecture</p><p>Suggested verdict: update existing draft</p></div></section></div>${run}</div>`;
		case "contact": return `<div class="contact"><div>${source}<section class="sheet">${Array.from({ length: 15 }, (_, i) => `<div class="thumb">image-${i + 1}<br><small>${i % 4 ? "ready" : "alt needed"}</small></div>`).join("")}</section></div>${run}</div>`;
		case "cockpit": return `<div class="cockpit"><section class="telemetry"><h2>Runner</h2><div class="dial">0%</div></section><div>${source}</div>${run}</div>`;
		case "assistant": return `<div class="assistant"><div>${source}<section class="chat"><div class="bubble">I found a GitHub docs folder. Want drafts first?</div><div class="bubble user">Yes, and ask about old URLs.</div><div class="bubble">Ready. Review import starts the durable run.</div></section></div>${run}</div>`;
		case "ledger": return `<div class="ledger"><div>${source}<section class="entries"><div class="entry"><b>Source</b><span>GitHub docs folder accepted</span><em>signed</em></div><div class="entry"><b>Policy</b><span>Old URLs require review</span><em>pending</em></div><div class="entry"><b>Write</b><span>Draft pages only</span><em>ready</em></div></section></div>${run}</div>`;
		case "command": return `<div class="single">${source}<section class="line">import github:WordPress/gutenberg/docs/explanations/architecture --drafts --media --ask-urls</section><section class="expanded"><div>Source parsed</div><div>31 pages</div><div>18 media</div><div>5 URL decisions</div></section>${run}</div>`;
		default: return `<div class="grid">${source}${output}${run}</div>`;
	}
}

function controlsFor(mode) {
	return ({
		radial: "URLs Media Authors Conflicts",
		palette: "Commands Shortcuts Recent Explain",
		calendar: "Today Recurring Missed History",
		ide: "Tree Preview Blocks Diff",
		storyboard: "Source Transform Decide Finish",
		checklist: "Ready Risks Options Run",
		matrix: "Authors Media Terms URLs",
		tree: "Folders Files Parsed Selected",
		policy: "Rewrite Preserve External Test",
		compare: "DryRun Drafts Publish Rollback",
		heatmap: "Density Errors Media URLs",
		sitemap: "Parents Slugs Drafts Links",
		package: "Accepted Scanned Packed Delivered",
		access: "Headings AltText Links Structure",
		court: "Evidence Suggested Verdict Appeal",
		contact: "AltText Galleries Missing Duplicates",
		cockpit: "Runner Source Decisions Output",
		assistant: "Explain Recommend Advanced Confirm",
		ledger: "Entries Signed Pending Export",
		command: "Parse Expand Save Run",
	}[mode] || "Inspect Map Media Review");
}

for (const concept of concepts) {
	writeFileSync(join(optionDir, `option-${pad(concept.n)}.html`), shell(concept, layout(concept)));
}

const nav = concepts.map((concept) => `<a href="#proposal-${pad(concept.n)}">${pad(concept.n)} ${esc(concept.title)}</a>`).join("");
const cards = concepts.map((concept) => `<section class="proposal ${concept.mode}" id="proposal-${pad(concept.n)}"><header><div><h2>${pad(concept.n)} ${esc(concept.title)}</h2><p>${esc(concept.desc)}</p></div><a href="options/option-${pad(concept.n)}.html" target="_blank" rel="noopener">Open full page</a></header><iframe src="options/option-${pad(concept.n)}.html" title="${pad(concept.n)} ${esc(concept.title)}"></iframe></section>`).join("\n");
writeFileSync(join(root, "index.html"), `<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Importer Progress Flow Explorations v3</title>
<style>*{box-sizing:border-box}body{margin:0;background:#f0f0f1;color:#1d2327;font:14px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}a{color:#2271b1;text-decoration:none}main{max-width:1900px;margin:auto;padding:20px}.hero{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:16px;align-items:end}.hero h1{font-size:30px;margin:0 0 6px}.hero p{margin:0;color:#646970}.nav{position:sticky;top:0;z-index:3;background:#f0f0f1;border-bottom:1px solid #dcdcde;padding:10px 0;display:flex;gap:8px;overflow:auto}.nav a,.proposal header a{background:#fff;border:1px solid #dcdcde;border-radius:999px;padding:7px 10px;white-space:nowrap}.gallery{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.proposal{background:#fff;border:1px solid #dcdcde;border-radius:8px;overflow:hidden;box-shadow:0 1px 2px rgba(0,0,0,.05)}.proposal header{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;align-items:center;min-height:78px;padding:12px 14px;border-bottom:1px solid #dcdcde}.proposal h2{font-size:17px;margin:0 0 4px}.proposal p{margin:0;color:#646970}iframe{display:block;width:100%;height:640px;border:0;background:#fff}@media(max-width:1100px){.gallery{grid-template-columns:1fr}.hero{display:block}iframe{height:760px}}@media(max-width:700px){main{padding:12px}.proposal header{display:block}iframe{height:860px}}</style></head>
<body><main><header class="hero"><div><h1>Universal Importer: 20 More Distinct Journeys</h1><p>v3 adds 20 new interaction models, intentionally different from the previous gallery.</p></div><strong>v3 / options 21-40</strong></header><nav class="nav">${nav}</nav><section class="gallery">${cards}</section></main></body></html>`);

writeFileSync(join(root, "qa-notes.md"), `# QA notes - v3 importer proposals

Generated 20 additional self-contained importer proposal pages:

- Gallery: \`index.html\`
- Options: \`options/option-21.html\` through \`options/option-40.html\`

Each option includes a first-screen source input, feature discovery buttons, and a simulated import run that progresses to completion.
`);
