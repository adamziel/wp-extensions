// End-to-end smoke test for the option-30 transcript flow.
// Loads the real rendered admin HTML in headless Chromium, drives the
// flow programmatically, intercepts fetch, and confirms the source URL
// reaches the AJAX payload.
//
// Usage: node tools/verify-option-30-flow.js <chromium>

const fs = require('fs');
const os = require('os');
const path = require('path');
const childProcess = require('child_process');

const repoRoot = path.resolve(__dirname, '..');
const chromium = process.argv[2];

if (!chromium) {
	process.stderr.write('Usage: node tools/verify-option-30-flow.js <chromium>\n');
	process.exit(2);
}

const snapshotPath = path.join(repoRoot, 'snapshot.html');
if (!fs.existsSync(snapshotPath)) {
	process.stderr.write('snapshot.html not found — run `php tools/render-admin-snapshot.php` first.\n');
	process.exit(2);
}

// Take the rendered admin HTML, then append a test driver that:
//   - replaces window.fetch with an interceptor
//   - waits for the IIFE to initialise
//   - types a URL into the source memo
//   - clicks Continue, Continue, Review, Start import
//   - reports the captured payload via data-test-result attributes.
const baseHtml = fs.readFileSync(snapshotPath, 'utf8');

const driver = `
<script>
function fail(message) {
	document.body.setAttribute('data-test-result', 'fail');
	document.body.setAttribute('data-test-message', String(message));
}
function pass(payload) {
	document.body.setAttribute('data-test-result', 'pass');
	document.body.setAttribute('data-test-payload', JSON.stringify(payload || {}));
}
function sleep(ms) { return new Promise(function(r){ setTimeout(r, ms); }); }

(async function drive() {
	try {
		await sleep(250); // let the inline IIFE attach handlers
		var source = document.getElementById('universal-importer-source');
		if (!source) { return fail('source input missing'); }

		source.value = 'https://github.com/WordPress/gutenberg/tree/trunk/docs';
		source.dispatchEvent(new Event('input', { bubbles: true }));

		var srcContinue = document.getElementById('universal-importer-source-continue');
		if (!srcContinue) { return fail('source continue button missing'); }
		srcContinue.click();
		await sleep(60);

		// After Continue, source memo should be replaced by a locked summary,
		// and Classify turn should appear with its own Continue.
		var sourceTurn = document.getElementById('universal-importer-turn-source');
		var sourceStillInForm = sourceTurn && sourceTurn.parentNode && sourceTurn.parentNode.id === 'universal-importer-turns';

		var classifyTurn = document.querySelector('[data-turn-key="classify"]:not([hidden])');
		// templates are inside <template>; rendered live nodes have parents in #universal-importer-turns
		var liveClassify = Array.prototype.slice.call(document.querySelectorAll('[data-turn-key="classify"]'))
			.find(function(node){ return node.parentNode && node.parentNode.id === 'universal-importer-turns'; });
		if (!liveClassify) { return fail('classify turn was not rendered after source Continue'); }

		var classifyContinue = liveClassify.querySelector('[data-action="continue"]');
		if (!classifyContinue) { return fail('classify continue button missing'); }
		// Enter-to-advance: the Continue button should be auto-focused.
		if (document.activeElement !== classifyContinue) {
			return fail('Classify Continue not auto-focused. activeElement=' + (document.activeElement && document.activeElement.tagName) + ' / ' + (document.activeElement && document.activeElement.getAttribute && document.activeElement.getAttribute('data-action')));
		}
		classifyContinue.click();
		await sleep(60);

		var liveConfigure = Array.prototype.slice.call(document.querySelectorAll('[data-turn-key="configure"]'))
			.find(function(node){ return node.parentNode && node.parentNode.id === 'universal-importer-turns'; });
		if (!liveConfigure) { return fail('configure turn was not rendered after classify Continue'); }

		var configureContinue = liveConfigure.querySelector('[data-action="continue"]');
		if (!configureContinue) { return fail('configure Review button missing'); }
		if (document.activeElement !== configureContinue) {
			return fail('Configure Review not auto-focused. activeElement=' + (document.activeElement && document.activeElement.tagName) + ' / ' + (document.activeElement && document.activeElement.getAttribute && document.activeElement.getAttribute('data-action')));
		}
		configureContinue.click();
		await sleep(60);

		var liveConfirm = Array.prototype.slice.call(document.querySelectorAll('[data-turn-key="confirm"]'))
			.find(function(node){ return node.parentNode && node.parentNode.id === 'universal-importer-turns'; });
		if (!liveConfirm) { return fail('confirm turn was not rendered after configure Review'); }

		// Enter-to-advance from Confirm: Start button should be auto-focused.
		var startBtn = document.activeElement;
		if (!startBtn || startBtn.getAttribute('data-action') !== 'start') {
			return fail('after Configure, Start on Confirm did not auto-focus. Got: ' + (startBtn && startBtn.tagName) + ' / ' + (startBtn && startBtn.getAttribute && startBtn.getAttribute('data-action')));
		}

		// Diagnostic: at this point the source memo's <input> should be detached
		// from the form. Capture its parent + value before submit.
		var sourceEl = document.getElementById('universal-importer-source');
		document.body.setAttribute('data-diag-source-value', sourceEl ? String(sourceEl.value || '') : '(missing)');
		document.body.setAttribute('data-diag-source-parent', sourceEl && sourceEl.parentNode ? sourceEl.parentNode.tagName + '#' + (sourceEl.parentNode.id || '(no id)') : '(no parent)');
		// Verify the form still contains the start button.
		var formForDiag = document.getElementById('universal-importer-start-form');
		document.body.setAttribute('data-diag-form-contains-start', formForDiag && formForDiag.contains(startBtn) ? 'yes' : 'no');
		document.body.setAttribute('data-diag-btn-type', startBtn.getAttribute('type') || '(none)');

		// Attach diagnostic listener on the form to see whether the submit event fires at all.
		window.__submitFired = 0;
		formForDiag.addEventListener('submit', function(){ window.__submitFired++; });

		// Trigger submit. Try both the button click and an explicit form.requestSubmit().
		startBtn.click();
		await sleep(80);
		document.body.setAttribute('data-diag-submit-fired', String(window.__submitFired));
		document.body.setAttribute('data-diag-form-checkvalidity', formForDiag.checkValidity ? String(formForDiag.checkValidity()) : '(no checkValidity)');
		// What inputs are required and invalid?
		var invalid = [];
		Array.prototype.slice.call(formForDiag.querySelectorAll('input,select,textarea')).forEach(function(el){
			if (el.willValidate && !el.checkValidity()) {
				invalid.push((el.tagName || '') + '#' + (el.id || '') + '[name=' + (el.name || '') + ', required=' + (el.required ? '1' : '0') + ', value="' + String(el.value || '').slice(0,30) + '"]');
			}
		});
		document.body.setAttribute('data-diag-invalid-inputs', invalid.join('; ') || '(none)');
		document.body.setAttribute('data-diag-fetch-count', String((window.__fetchCalls || []).length));
		document.body.setAttribute('data-diag-fetch-is-hook', String(window.fetch && /window.__fetchCalls/.test(String(window.fetch))));
		document.body.setAttribute('data-diag-errors', JSON.stringify((window.__earlyErrors || []).slice(0, 5)));
		// Capture last fetch call body if any.
		var lastFetch = (window.__fetchCalls || [])[(window.__fetchCalls || []).length - 1];
		if (lastFetch) {
			var b = lastFetch.options && lastFetch.options.body;
			var bs = '';
			if (typeof b === 'string') { bs = b; }
			else if (b instanceof URLSearchParams) { bs = b.toString(); }
			else if (b instanceof FormData) {
				var pairs = []; b.forEach(function(v, k){ pairs.push(k + '=' + (typeof v === 'string' ? v : '[non-string]')); }); bs = pairs.join('&');
			}
			document.body.setAttribute('data-diag-fetch-body', bs.slice(0, 500));
			document.body.setAttribute('data-diag-fetch-url', String(lastFetch.url || '').slice(0, 200));
		}
		if (window.__xhrCalls.length === 0 && formForDiag && formForDiag.requestSubmit) {
			try { formForDiag.requestSubmit(); } catch (e) { document.body.setAttribute('data-diag-requestSubmit-error', e.message); }
			await sleep(80);
		}
		if (window.__xhrCalls.length === 0 && formForDiag) {
			// Last resort: dispatch the submit event.
			var ev = new Event('submit', { bubbles: true, cancelable: true });
			formForDiag.dispatchEvent(ev);
			await sleep(80);
		}
		document.body.setAttribute('data-diag-xhr-count', String(window.__xhrCalls.length));

		// Inspect the captured fetch calls. The hook records body as a string directly.
		var calls = (window.__fetchCalls || []).map(function(c){ return { url: c.url, body: c.body }; });
		var createCall = calls.filter(function(c){ return /action=universal_importer_create_session/.test(c.body || '') || /action=universal_importer_upload_session/.test(c.body || ''); }).pop();
		if (!createCall) {
			return fail('No create/upload AJAX call captured. fetches=' + JSON.stringify(calls));
		}

		// Parse the URL-encoded body to extract the source value.
		var parsed = {};
		(createCall.body || '').split('&').forEach(function(pair){
			var i = pair.indexOf('=');
			if (i === -1) { return; }
			parsed[decodeURIComponent(pair.slice(0, i).replace(/\\+/g, ' '))] = decodeURIComponent(pair.slice(i+1).replace(/\\+/g, ' '));
		});

		if (!parsed.source || parsed.source !== 'https://github.com/WordPress/gutenberg/tree/trunk/docs') {
			return fail('Source URL missing or wrong in payload. parsed.source="' + (parsed.source || '') + '" full body=' + createCall.body);
		}

		pass({
			ajaxUrl: createCall.url,
			source: parsed.source,
			action: parsed.action,
			urlRewriteMode: parsed.url_rewrite_mode,
			dryRun: parsed.dry_run,
			drafts: parsed.import_as_drafts,
			fetchCallCount: calls.length
		});
	} catch (e) {
		fail('Exception: ' + (e && e.message ? e.message : String(e)));
	}
})();
</script>`;

// The admin JS uses fetch via a 'request' helper. Wrap fetch BEFORE the
// admin script runs so we capture its body even if the admin script
// stashes a fetch reference. Also catch any IIFE errors.
const xhrHook = `
<script>
window.ajaxurl = 'admin-ajax.php';
window.__fetchCalls = [];
window.__earlyErrors = [];
window.addEventListener('error', function(ev){ window.__earlyErrors.push(String(ev.message || ev.error || '')); });
window.addEventListener('unhandledrejection', function(ev){ window.__earlyErrors.push('unhandled: ' + String((ev.reason && ev.reason.message) || ev.reason || '')); });
var __origFetch = window.fetch;
window.fetch = function(url, options) {
	var bodyStr = '';
	if (options && options.body) {
		if (typeof options.body === 'string') { bodyStr = options.body; }
		else if (options.body instanceof URLSearchParams) { bodyStr = options.body.toString(); }
		else if (options.body instanceof FormData) {
			var pairs = [];
			options.body.forEach(function(v, k){ pairs.push(k + '=' + (typeof v === 'string' ? v : '[' + (v && v.name) + ']')); });
			bodyStr = pairs.join('&');
		}
	}
	window.__fetchCalls.push({ url: String(url || ''), method: (options && options.method) || 'GET', body: bodyStr });
	return Promise.resolve({
		ok: true,
		status: 200,
		text: function() {
			return Promise.resolve(JSON.stringify({
				success: true,
				data: {
					id: 'session-test', source: 'https://github.com/WordPress/gutenberg/tree/trunk/docs',
					status: 'queued', dry_run: false,
					progress: { total: 0, completed: 0, errors: 0 },
					recent_events: [], pending_decisions: []
				}
			}));
		}
	});
};
window.__xhrCalls = [];
(function(){
	var OrigOpen = XMLHttpRequest.prototype.open;
	var OrigSend = XMLHttpRequest.prototype.send;
	XMLHttpRequest.prototype.open = function(method, url) {
		this.__url = String(url || '');
		this.__method = String(method || '');
		return OrigOpen.apply(this, arguments);
	};
	XMLHttpRequest.prototype.send = function(body) {
		var bodyStr = body;
		if (body && body instanceof URLSearchParams) { bodyStr = body.toString(); }
		else if (body && body instanceof FormData) {
			var entries = [];
			body.forEach(function(value, key) {
				if (value instanceof File) { entries.push(key + '=[File:' + value.name + ']'); }
				else { entries.push(key + '=' + value); }
			});
			bodyStr = entries.join('&');
		}
		else if (body && typeof body !== 'string') { bodyStr = String(body); }
		window.__xhrCalls.push({ url: this.__url, method: this.__method, body: bodyStr });
		// Short-circuit the request with a fake successful response.
		var self = this;
		setTimeout(function(){
			Object.defineProperty(self, 'status', { value: 200, configurable: true });
			Object.defineProperty(self, 'responseText', { value: JSON.stringify({
				success: true,
				data: {
					id: 'session-test', source: 'https://github.com/WordPress/gutenberg/tree/trunk/docs',
					status: 'queued', dry_run: false,
					progress: { total: 0, completed: 0, errors: 0 },
					recent_events: [], pending_decisions: []
				}
			}), configurable: true });
			Object.defineProperty(self, 'readyState', { value: 4, configurable: true });
			if (typeof self.onreadystatechange === 'function') { self.onreadystatechange(); }
			if (typeof self.onload === 'function') { self.onload(); }
		}, 0);
	};
})();
</script>`;

// Inject XHR hook before the admin <script> runs.
let html = baseHtml.replace(/<script>/, xhrHook + '<script>');
// Append the driver at the end of the body.
html = html.replace(/<\/body>/, driver + '</body>');

const tempDir = fs.mkdtempSync(path.join(os.tmpdir(), 'verify-option-30-'));
const htmlPath = path.join(tempDir, 'verify.html');
const profileDir = path.join(tempDir, 'profile');
fs.chmodSync(tempDir, 0o700);
fs.mkdirSync(profileDir, { mode: 0o700 });
fs.writeFileSync(htmlPath, html);

const result = childProcess.spawnSync(
	chromium,
	[
		'--headless',
		'--disable-gpu',
		'--no-sandbox',
		'--disable-dev-shm-usage',
		'--user-data-dir=' + profileDir,
		'--virtual-time-budget=10000',
		'--dump-dom',
		'file://' + htmlPath
	],
	{ encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 }
);

const out = (result.stdout || '') + '\n' + (result.stderr || '');
const verdict = /data-test-result="(pass|fail)"/.exec(out);
const message = /data-test-message="([^"]+)"/.exec(out);
const payload = /data-test-payload="([^"]+)"/.exec(out);

// Extract all diagnostic attributes
const diagRe = /data-(diag-[a-z-]+)="([^"]*)"/g;
const diagnostics = {};
let m;
while ((m = diagRe.exec(out)) !== null) {
	diagnostics[m[1]] = m[2];
}
if (Object.keys(diagnostics).length > 0) {
	console.log('Diagnostics:');
	for (const k of Object.keys(diagnostics)) {
		console.log('  ' + k + ' = ' + diagnostics[k]);
	}
}

fs.rmSync(tempDir, { recursive: true, force: true });

if (!verdict) {
	process.stderr.write('No test result attribute found in DOM.\n');
	process.stderr.write(out.slice(0, 4000) + '\n');
	process.exit(1);
}

console.log('Verdict:', verdict[1]);
if (message) { console.log('Message:', message[1].replace(/&quot;/g, '"')); }
if (payload) {
	try {
		const decoded = JSON.parse(payload[1].replace(/&quot;/g, '"').replace(/&amp;/g, '&'));
		console.log('Payload:', JSON.stringify(decoded, null, 2));
	} catch (_) {
		console.log('Payload (raw):', payload[1].slice(0, 1000));
	}
}
process.exit(verdict[1] === 'pass' ? 0 : 1);
