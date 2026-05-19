const fs = require('fs');
const os = require('os');
const path = require('path');
const childProcess = require('child_process');

const repoRoot = process.argv[2];
const chromium = process.argv[3];

process.umask(0o077);

if (!repoRoot || !chromium) {
	process.stderr.write('Usage: node admin-browser-upload-chromium-test.js <repo-root> <chromium>\n');
	process.exit(2);
}

const adminPage = fs.readFileSync(path.join(repoRoot, 'src/Admin/ImportAdminPage.php'), 'utf8');
const scriptMatch = adminPage.match(/<script>\s*([\s\S]*?)\s*<\/script>/);

if (!scriptMatch) {
	throw new Error('Admin browser script was not found.');
}

let script = scriptMatch[1];
script = script.replace(/var config = <\?php[\s\S]*?\?>;/, "var config = { nonce: 'nonce', sessions: [] };");
script = script.replace(/<\?php[\s\S]*?\?>/g, 'test');

const html = `<!doctype html>
<html>
<body>
	<div id="universal-importer-notice" class="notice" style="display:none"><p></p></div>
	<form id="universal-importer-start-form">
		<input type="text" id="universal-importer-source" name="source" required>
		<input type="text" id="universal-importer-domains" name="confirmed_domains">
		<input type="radio" name="url_rewrite_mode" value="ask" checked>
		<input type="checkbox" name="dry_run" value="1" checked>
		<div id="universal-importer-dropzone">
			<input type="file" id="universal-importer-file-picker" multiple accept=".pdf,.epub,.html,.htm,.md,.markdown,.txt,.xml,.wxr,.zip,application/pdf,application/epub+zip,text/html,text/markdown,text/plain,application/xml,text/xml,application/zip">
			<input type="file" id="universal-importer-folder-picker" multiple webkitdirectory directory>
			<button type="button" id="universal-importer-clear-files" disabled>Clear selection</button>
			<p id="universal-importer-file-summary"></p>
			<ul id="universal-importer-file-preview"></ul>
		</div>
	</form>
	<div id="universal-importer-sessions"></div>
	<script>
	window.ajaxurl = 'admin-ajax.php';
	window.__fetchCalls = [];
	window.fetch = function(url, options) {
		window.__fetchCalls.push({ url: url, options: options });
		return Promise.resolve({
			ok: true,
			status: 200,
			text: function() {
				return Promise.resolve(JSON.stringify({
					success: true,
					data: {
						id: 'session-1',
						source: 'browser-upload',
						status: 'done',
						dry_run: true,
						progress: { total: 0, completed: 0, errors: 0 },
						recent_events: [],
						pending_decisions: []
					}
				}));
			}
		});
	};
	</script>
	<script>${script}</script>
	<script>
	function fileEntry(name) {
		return {
			isFile: true,
			isDirectory: false,
			name: name,
			file: function(resolve) {
				resolve(new File(['fixture'], name, { type: 'text/plain' }));
			}
		};
	}

	function directoryEntry(name, children) {
		return {
			isFile: false,
			isDirectory: true,
			name: name,
			createReader: function() {
				var read = false;
				return {
					readEntries: function(resolve) {
						if (read) {
							resolve([]);
							return;
						}
						read = true;
						resolve(children);
					}
				};
			}
		};
	}

	function fail(message) {
		document.body.setAttribute('data-test-result', 'fail');
		document.body.setAttribute('data-test-message', message);
	}

	function pass() {
		document.body.setAttribute('data-test-result', 'pass');
	}

	function dispatchDragEvent(element, type, dataTransfer) {
		var event = new Event(type, { bubbles: true, cancelable: true });
		Object.defineProperty(event, 'dataTransfer', {
			configurable: true,
			value: dataTransfer
		});
		element.dispatchEvent(event);
	}

	(async function() {
		try {
			var dropzone = document.getElementById('universal-importer-dropzone');
			var sourceInput = document.getElementById('universal-importer-source');
			var fileSummary = document.getElementById('universal-importer-file-summary');
			var clearFilesButton = document.getElementById('universal-importer-clear-files');
			var form = document.getElementById('universal-importer-start-form');
			var droppedTree = directoryEntry('Book', [
				fileEntry('chapter.md'),
				directoryEntry('assets', [
					fileEntry('cover.jpg')
				])
			]);

			var dataTransfer = {
				items: [
					{ webkitGetAsEntry: function() { return droppedTree; } },
					{ webkitGetAsEntry: function() { return fileEntry('loose.md'); } }
				],
				files: []
			};

			dispatchDragEvent(dropzone, 'dragover', dataTransfer);
			dispatchDragEvent(dropzone, 'drop', dataTransfer);

			await new Promise(function(resolve) { setTimeout(resolve, 50); });

			if (!fileSummary.textContent.includes('3 files ready')) {
				fail('Dropped directory files were not summarized: ' + fileSummary.textContent);
				return;
			}

			if (sourceInput.required) {
				fail('Source input should not be required after browser files are selected.');
				return;
			}

			if (clearFilesButton.disabled) {
				fail('Clear selection button should be enabled after browser files are selected.');
				return;
			}

			clearFilesButton.click();

			if (!sourceInput.required) {
				fail('Source input should become required after clearing browser files.');
				return;
			}

			if (fileSummary.textContent !== '') {
				fail('File summary should be cleared after clearing browser files.');
				return;
			}

			dispatchDragEvent(dropzone, 'drop', dataTransfer);

			await new Promise(function(resolve) { setTimeout(resolve, 50); });

			form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
			await new Promise(function(resolve) { setTimeout(resolve, 50); });

			var uploadCall = window.__fetchCalls.find(function(call) {
				return call.options && call.options.body instanceof FormData;
			});

			if (!uploadCall) {
				fail('Browser upload request was not sent as FormData.');
				return;
			}

			var paths = [];
			var filenames = [];
			var urlRewriteMode = '';
			for (var pair of uploadCall.options.body.entries()) {
				if (pair[0] === 'paths[]') {
					paths.push(pair[1]);
				}
				if (pair[0] === 'files[]') {
					filenames.push(pair[1].name);
				}
				if (pair[0] === 'url_rewrite_mode') {
					urlRewriteMode = pair[1];
				}
			}
			paths.sort();
			filenames.sort();

			if (JSON.stringify(paths) !== JSON.stringify(['Book/assets/cover.jpg', 'Book/chapter.md', 'loose.md'])) {
				fail('Unexpected upload paths: ' + JSON.stringify(paths));
				return;
			}

			if (JSON.stringify(filenames) !== JSON.stringify(['chapter.md', 'cover.jpg', 'loose.md'])) {
				fail('Unexpected upload filenames: ' + JSON.stringify(filenames));
				return;
			}

			if (urlRewriteMode !== 'ask') {
				fail('Upload request did not include the URL rewrite mode.');
				return;
			}

			pass();
		} catch (error) {
			fail(error && error.stack ? error.stack : String(error));
		}
	})();
	</script>
</body>
</html>`;

const tempDir = fs.mkdtempSync(path.join(os.tmpdir(), 'universal-importer-chromium-'));
const htmlPath = path.join(tempDir, 'browser-upload.html');
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
		'--virtual-time-budget=5000',
		'--dump-dom',
		htmlPath
	],
	{ encoding: 'utf8' }
);

fs.rmSync(tempDir, { recursive: true, force: true });

if (result.error) {
	throw result.error;
}

if (result.status !== 0) {
	process.stderr.write((result.stdout || '') + (result.stderr || ''));
	process.exit(result.status || 1);
}

if (!/data-test-result="pass"/.test(result.stdout || '')) {
	process.stderr.write((result.stdout || '') + (result.stderr || ''));
	process.exit(1);
}
