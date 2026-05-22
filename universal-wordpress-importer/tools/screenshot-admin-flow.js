// Screenshot the importer admin page at each transcript state.
//
// For each state in [empty, source-typed, source-locked, classify, configure, confirm],
// spawns headless Chromium, has the driver advance to that state, then captures a PNG.
//
// Usage: node tools/screenshot-admin-flow.js <chromium> <out-dir> [viewport-width]

const fs = require('fs');
const os = require('os');
const path = require('path');
const childProcess = require('child_process');

const repoRoot = path.resolve(__dirname, '..');
const chromium = process.argv[2];
const outDir = process.argv[3] || path.join(repoRoot, 'screenshots');
const viewportWidth = parseInt(process.argv[4] || '1280', 10);

if (!chromium) {
	process.stderr.write('Usage: node tools/screenshot-admin-flow.js <chromium> <out-dir> [viewport-width]\n');
	process.exit(2);
}

const snapshotPath = path.join(repoRoot, 'snapshot.html');
if (!fs.existsSync(snapshotPath)) {
	process.stderr.write('snapshot.html not found — run `php tools/render-admin-snapshot.php` first.\n');
	process.exit(2);
}

fs.mkdirSync(outDir, { recursive: true });

const baseHtml = fs.readFileSync(snapshotPath, 'utf8');

// Hooks for each shot. STATE controls how far the driver advances.
const STATES = [
	{ name: 'a-empty',         advance: 'empty' },
	{ name: 'b-source-typed',  advance: 'source-typed' },
	{ name: 'c-configure',     advance: 'configure' },
	{ name: 'd-confirm',       advance: 'confirm' }
];

function buildHtml(advance) {
	const xhrAndFetchHook = `
<script>
window.ajaxurl = 'admin-ajax.php';
window.__fetchCalls = [];
window.fetch = function(url, options) {
	window.__fetchCalls.push({ url: String(url || ''), method: (options && options.method) || 'GET' });
	return Promise.resolve({ ok: true, status: 200, text: function() {
		return Promise.resolve(JSON.stringify({ success: true, data: { id: 'session', source: '', status: 'queued', dry_run: false, progress: { total: 0, completed: 0, errors: 0 }, recent_events: [], pending_decisions: [] } }));
	} });
};
</script>`;
	const driver = `
<script>
function sleep(ms) { return new Promise(function(r){ setTimeout(r, ms); }); }
(async function drive() {
	try {
		await sleep(200);
		var advance = ${JSON.stringify(advance)};
		var source = document.getElementById('universal-importer-source');
		if (advance === 'empty') { return; }
		if (!source) { return; }

		source.value = 'https://github.com/WordPress/gutenberg/tree/trunk/docs';
		source.dispatchEvent(new Event('input', { bubbles: true }));
		if (advance === 'source-typed') { return; }

		document.getElementById('universal-importer-source-continue').click();
		await sleep(80);
		if (advance === 'configure') { return; }

		var liveConfigure = Array.prototype.slice.call(document.querySelectorAll('[data-turn-key="configure"]'))
			.find(function(node){ return node.parentNode && node.parentNode.id === 'universal-importer-turns'; });
		liveConfigure.querySelector('[data-action="continue"]').click();
		await sleep(80);
		// confirm state target — leave us here
	} catch(e) {
		document.body.setAttribute('data-drive-error', e.message);
	}
})();
</script>`;

	let html = baseHtml.replace(/<script>/, xhrAndFetchHook + '<script>');
	html = html.replace(/<\/body>/, driver + '</body>');
	return html;
}

function shot(state) {
	const tempDir = fs.mkdtempSync(path.join(os.tmpdir(), 'shot-'));
	fs.chmodSync(tempDir, 0o700);
	const htmlPath = path.join(tempDir, 'page.html');
	const profileDir = path.join(tempDir, 'profile');
	fs.mkdirSync(profileDir, { mode: 0o700 });
	fs.writeFileSync(htmlPath, buildHtml(state.advance));

	const outPath = path.join(outDir, state.name + '-' + viewportWidth + '.png');

	const result = childProcess.spawnSync(
		chromium,
		[
			'--headless=new',
			'--disable-gpu',
			'--no-sandbox',
			'--disable-dev-shm-usage',
			'--hide-scrollbars',
			'--user-data-dir=' + profileDir,
			'--virtual-time-budget=2000',
			'--window-size=' + viewportWidth + ',1500',
			'--screenshot=' + outPath,
			'file://' + htmlPath
		],
		{ encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 }
	);

	fs.rmSync(tempDir, { recursive: true, force: true });

	if (result.status !== 0 || !fs.existsSync(outPath)) {
		process.stderr.write('FAIL ' + state.name + ': ' + (result.stderr || '').slice(0, 500) + '\n');
		return null;
	}
	const size = fs.statSync(outPath).size;
	return { path: outPath, size: size };
}

console.log('Screenshotting ' + STATES.length + ' states at viewport ' + viewportWidth + 'px →');
for (const state of STATES) {
	const r = shot(state);
	if (r) { console.log('  ' + state.name + ' ' + r.size + 'B → ' + r.path); }
}
