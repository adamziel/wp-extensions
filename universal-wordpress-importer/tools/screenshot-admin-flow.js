// Screenshot the importer admin page at each transcript state.
//
// For each state, spawns headless Chromium with a hook + driver script appended
// to snapshot.html, advances the UI to the target state, then captures a PNG.
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

// Each state has:
//   name            output filename prefix
//   advance         which point in the driver to stop at
//   url             value typed into the source URL field (if any)
//   stallPicker     whether to leave the github_directories fetch pending
const STATES = [
	{ name: 'a-empty',             advance: 'empty' },
	{ name: 'b-url-typed-github',  advance: 'source-typed', url: 'https://github.com/WordPress/gutenberg/tree/trunk/docs' },
	{ name: 'b2-url-typed-wp',     advance: 'source-typed', url: 'https://example.com/wp-json/' },
	{ name: 'b3-url-typed-feed',   advance: 'source-typed', url: 'https://example.com/feed.xml' },
	{ name: 'c-picker-loading',    advance: 'picker-loading', url: 'https://github.com/WordPress/gutenberg/tree/trunk/docs', stallPicker: true },
	{ name: 'd-configure',         advance: 'configure', url: 'https://github.com/WordPress/gutenberg/tree/trunk/docs' },
	{ name: 'e-confirm',           advance: 'confirm', url: 'https://github.com/WordPress/gutenberg/tree/trunk/docs' }
];

function buildHtml(state) {
	const stallPicker = !!state.stallPicker;
	const xhrAndFetchHook = `
<script>
window.ajaxurl = 'admin-ajax.php';
window.__fetchCalls = [];
window.__stallPicker = ${stallPicker ? 'true' : 'false'};
window.fetch = function(url, options) {
	var bodyStr = '';
	try { bodyStr = String((options && options.body) || ''); } catch(e) {}
	window.__fetchCalls.push({ url: String(url || ''), method: (options && options.method) || 'GET', body: bodyStr });
	if (window.__stallPicker && /github_directories/.test(bodyStr)) {
		// Leave the picker fetch pending so the loading skeleton stays on screen.
		return new Promise(function(){ /* never resolves */ });
	}
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
		var advance = ${JSON.stringify(state.advance)};
		var url = ${JSON.stringify(state.url || '')};
		var source = document.getElementById('universal-importer-source');

		if (advance === 'empty') {
			if (source && typeof source.focus === 'function') {
				try { source.focus(); } catch(e) {}
			}
			return;
		}
		if (!source) { return; }

		// Type the URL and fire both input + change so debounced handlers run.
		source.value = url;
		source.dispatchEvent(new Event('input', { bubbles: true }));
		source.dispatchEvent(new Event('change', { bubbles: true }));
		await sleep(120);

		if (advance === 'source-typed') { return; }

		if (advance === 'picker-loading') {
			// Try the new stable selector first, fall back to the legacy button id.
			var pickerTrigger =
				document.querySelector('[data-action="open-directory-picker"]') ||
				document.getElementById('universal-importer-github-browse');
			if (pickerTrigger) {
				pickerTrigger.click();
			}
			// Let the modal render + skeleton paint while the fetch stays pending.
			await sleep(600);
			return;
		}

		document.getElementById('universal-importer-source-continue').click();
		await sleep(80);
		if (advance === 'configure') { return; }

		var liveConfigure = Array.prototype.slice.call(document.querySelectorAll('[data-turn-key="configure"]'))
			.find(function(node){ return node.parentNode && node.parentNode.id === 'universal-importer-turns'; });
		if (liveConfigure) {
			liveConfigure.querySelector('[data-action="continue"]').click();
		}
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
	fs.writeFileSync(htmlPath, buildHtml(state));

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
