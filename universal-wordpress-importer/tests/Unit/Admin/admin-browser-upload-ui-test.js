const fs = require('fs');
const vm = require('vm');

const repoRoot = process.argv[2];

if (!repoRoot) {
	process.stderr.write('Usage: node admin-browser-upload-ui-test.js <repo-root>\n');
	process.exit(2);
}

const adminPage = fs.readFileSync(repoRoot + '/src/Admin/ImportAdminPage.php', 'utf8');
const scriptMatch = adminPage.match(/<script>\s*([\s\S]*?)\s*<\/script>/);

if (!scriptMatch) {
	throw new Error('Admin browser script was not found.');
}

let script = scriptMatch[1];
script = script.replace(/var config = <\?php[\s\S]*?\?>;/, "var config = { nonce: 'nonce', sessions: [] };");
script = script.replace(/<\?php[\s\S]*?\?>/g, 'test');

class Element {
	constructor(id) {
		this.id = id;
		this.listeners = {};
		this.required = true;
		this.textContent = '';
		this.innerHTML = '';
		this.children = [];
		this.style = {};
		this.firstChild = null;
		this.firstElementChild = {};
		this.classList = {
			add: () => {},
			remove: () => {}
		};
	}

	addEventListener(type, listener) {
		if (!this.listeners[type]) {
			this.listeners[type] = [];
		}
		this.listeners[type].push(listener);
	}

	dispatch(type, event) {
		(this.listeners[type] || []).forEach((listener) => listener(event));
	}

	querySelector() {
		return this.id === 'universal-importer-notice' ? noticeParagraph : null;
	}

	querySelectorAll() {
		return [];
	}

	appendChild(child) {
		this.children.push(child);
		this.textContent += child.textContent || '';
		return child;
	}

	insertBefore() {}
}

class FakeFormData {
	constructor(form) {
		this.entries = [];
		this.values = {};

		if (form && form.formValues) {
			Object.keys(form.formValues).forEach((key) => {
				this.set(key, form.formValues[key]);
			});
		}
	}

	set(key, value) {
		this.values[key] = value;
		this.entries.push({ key, value });
	}

	append(key, value, filename) {
		this.entries.push({ key, value, filename });
	}

	get(key) {
		return Object.prototype.hasOwnProperty.call(this.values, key) ? this.values[key] : '';
	}

	all(key) {
		return this.entries.filter((entry) => entry.key === key);
	}
}

function fileEntry(name) {
	return {
		isFile: true,
		isDirectory: false,
		name,
		file(resolve) {
			resolve({ name });
		}
	};
}

function directoryEntry(name, children) {
	return {
		isFile: false,
		isDirectory: true,
		name,
		createReader() {
			let read = false;
			return {
				readEntries(resolve) {
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

function flushPromises() {
	return new Promise((resolve) => setImmediate(resolve));
}

const form = new Element('universal-importer-start-form');
form.formValues = {
	source: '',
	confirmed_domains: '',
	url_rewrite_mode: 'ask',
	dry_run: '1'
};
const sourceInput = new Element('universal-importer-source');
const filePicker = new Element('universal-importer-file-picker');
filePicker.files = [];
const folderPicker = new Element('universal-importer-folder-picker');
folderPicker.files = [];
const clearFilesButton = new Element('universal-importer-clear-files');
const dropzone = new Element('universal-importer-dropzone');
const fileSummary = new Element('universal-importer-file-summary');
const filePreview = new Element('universal-importer-file-preview');
const sessions = new Element('universal-importer-sessions');
const notice = new Element('universal-importer-notice');
const noticeParagraph = { textContent: '' };
const fetchCalls = [];
let nextFetchResponse = {
	ok: true,
	status: 200,
	body: JSON.stringify({
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
	})
};

const elements = {
	'universal-importer-start-form': form,
	'universal-importer-source': sourceInput,
	'universal-importer-file-picker': filePicker,
	'universal-importer-folder-picker': folderPicker,
	'universal-importer-clear-files': clearFilesButton,
	'universal-importer-dropzone': dropzone,
	'universal-importer-file-summary': fileSummary,
	'universal-importer-file-preview': filePreview,
	'universal-importer-sessions': sessions,
	'universal-importer-notice': notice
};

const context = {
	Array,
	Error,
	Object,
	Promise,
	String,
	URLSearchParams,
	console,
	ajaxurl: 'admin-ajax.php',
	document: {
		getElementById(id) {
			return elements[id] || null;
		},
		createElement() {
			return new Element('created');
		}
	},
	fetch(url, options) {
		fetchCalls.push({ url, options });
		const response = nextFetchResponse;
		return Promise.resolve({
			ok: response.ok,
			status: response.status,
			text() {
				return Promise.resolve(response.body);
			}
		});
	},
	window: {
		FormData: FakeFormData,
		setInterval() {
			return 1;
		},
		clearInterval() {}
	},
	FormData: FakeFormData
};

vm.runInNewContext(script, context, { filename: 'ImportAdminPage.inline.js' });

filePicker.files = [{ name: 'Annual Report.pdf' }];
filePicker.dispatch('change', {});

if (!fileSummary.textContent.includes('1 file ready') || !fileSummary.textContent.includes('1 PDF')) {
	throw new Error('PDF file picker selection was not summarized: ' + fileSummary.textContent);
}

if (sourceInput.required) {
	throw new Error('Source input should not be required after a PDF is selected.');
}

if (clearFilesButton.disabled) {
	throw new Error('Clear selection button should be enabled after a PDF is selected.');
}

const droppedTree = directoryEntry('Book', [
	fileEntry('chapter.md'),
	directoryEntry('assets', [
		fileEntry('cover.jpg')
	])
]);

dropzone.dispatch('drop', {
	preventDefault() {},
	dataTransfer: {
		items: [
			{ webkitGetAsEntry: () => droppedTree },
			{ webkitGetAsEntry: () => fileEntry('loose.md') }
		],
		files: []
	}
});

(async () => {
	await flushPromises();
	await flushPromises();

	if (!fileSummary.textContent.includes('3 files ready')) {
		throw new Error('Dropped directory files were not summarized: ' + fileSummary.textContent);
	}

	if (sourceInput.required) {
		throw new Error('Source input should not be required after browser files are selected.');
	}

	clearFilesButton.dispatch('click', {});

	if (!sourceInput.required) {
		throw new Error('Source input should become required after browser files are cleared.');
	}

	if (fileSummary.textContent !== '') {
		throw new Error('File summary should be cleared after clearing browser files.');
	}

	dropzone.dispatch('drop', {
		preventDefault() {},
		dataTransfer: {
			items: [
				{ webkitGetAsEntry: () => droppedTree },
				{ webkitGetAsEntry: () => fileEntry('loose.md') }
			],
			files: []
		}
	});
	await flushPromises();
	await flushPromises();

	form.dispatch('submit', { preventDefault() {} });
	await flushPromises();
	await flushPromises();

	const uploadCall = fetchCalls.find((call) => call.options.body instanceof FakeFormData);

	if (!uploadCall) {
		throw new Error('Browser upload request was not sent as FormData.');
	}

	const body = uploadCall.options.body;
	const paths = body.all('paths[]').map((entry) => entry.value).sort();
	const filenames = body.all('files[]').map((entry) => entry.filename).sort();

	const expectedPaths = ['Book/assets/cover.jpg', 'Book/chapter.md', 'loose.md'];
	const expectedFilenames = ['chapter.md', 'cover.jpg', 'loose.md'];

	if (JSON.stringify(paths) !== JSON.stringify(expectedPaths)) {
		throw new Error('Unexpected upload paths: ' + JSON.stringify(paths));
	}

	if (JSON.stringify(filenames) !== JSON.stringify(expectedFilenames)) {
		throw new Error('Unexpected upload filenames: ' + JSON.stringify(filenames));
	}

	if (body.values.url_rewrite_mode !== 'ask') {
		throw new Error('Upload request did not include the URL rewrite mode.');
	}

	nextFetchResponse = {
		ok: false,
		status: 500,
		body: '<p>There has been a critical error on this website.</p>'
	};
	form.dispatch('submit', { preventDefault() {} });
	await flushPromises();
	await flushPromises();

	if (!noticeParagraph.textContent.includes('HTTP 500:') || !noticeParagraph.textContent.includes('There has been a critical error')) {
		throw new Error('Non-JSON AJAX failure was not surfaced clearly: ' + noticeParagraph.textContent);
	}

	if (noticeParagraph.textContent.includes('Unexpected token')) {
		throw new Error('Raw JSON parse error leaked into the admin notice: ' + noticeParagraph.textContent);
	}
})().catch((error) => {
	process.stderr.write(error.stack + '\n');
	process.exit(1);
});
