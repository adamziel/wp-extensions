# WP Extensions

Experiments for making WordPress a portable content workspace: bring source
material into WordPress, review and edit it there, then export a static
snapshot when the site is ready.

The main workflow is **PortPress**. It combines:

- **Universal WordPress Importer**: imports files, feeds, archives, PDFs,
  repositories, WXR exports, and existing sites into reviewable WordPress
  drafts.
- **StillPress**: exports public WordPress pages, rewritten same-site links,
  CSS, images, and other frontend assets as static files.

That loop is useful when you want WordPress for editorial review and migration
work, but you also want portable output that can be inspected, archived, or
hosted without a live WordPress backend.

<a href="https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fraw.githubusercontent.com%2Fadamziel%2Fwp-extensions%2Fmain%2Fblueprints%2Fportpress-demo.json">
  <img src="docs/assets/try-it-in-playground.svg" alt="Try in WordPress Playground" width="224" />
</a>

The Playground demo is defined in `blueprints/portpress-demo.json`. It opens a
guided checklist with both tools installed, sample source files ready to
import, and seeded WordPress content ready to export. Use this bundled import
source path in the demo:

```text
/wordpress/wp-content/uploads/portpress-source
```

Read the GitHub Pages docs at
[adamziel.github.io/wp-extensions](https://adamziel.github.io/wp-extensions/).
The Playground buttons in this repository use the official SVG asset from
[`WordPress/action-wp-playground-pr-preview`](https://github.com/WordPress/action-wp-playground-pr-preview/blob/main/assets/playground-preview-button.svg).

## Why this exists

WordPress migrations and static exports usually fail at the handoff points:
source material is messy, drafts need human review, URL and media decisions are
contextual, and a static export is only useful if links and assets can be
checked after the fact. This repository keeps those steps close together:

1. Import source material into WordPress drafts instead of publishing blindly.
2. Use WordPress as the review bench for titles, blocks, media, and URLs.
3. Export the reviewed public site as static HTML and assets.
4. Verify the result in a browser or local HTTP server before hosting it.

Playground demos make the flow disposable and zero-setup. A real WordPress
install or WP-CLI workflow is still the better path for persistent storage,
large imports, repeatable exports, and automation.

## PortPress docs site

`docs/` contains the static documentation site for the combined import/export
workflow. It includes:

- a first-run PortPress demo guide
- import setup, supported formats, and examples
- static export setup, demos, limitations, and verification steps
- a shared Playground button and PortPress visuals

GitHub Pages deploys the site from `docs/` when changes land on `main`.

## Universal WordPress Importer

`universal-wordpress-importer/` is a WordPress plugin for durable, resumable
imports from content trees: local folders, browser-dropped folders, zip
archives, Markdown, HTML, text, EPUB, WXR, PDFs, GitHub repositories,
WordPress REST/site URLs, and RSS/Atom feeds.

<a href="https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fraw.githubusercontent.com%2Fadamziel%2Fwp-extensions%2Fmain%2Fblueprints%2Funiversal-wordpress-importer-demo.json">
  <img src="docs/assets/try-it-in-playground.svg" alt="Try in WordPress Playground" width="224" />
</a>

The importer stores traversal progress, pending decisions, prepared documents,
media references, locks, and idempotency records in custom WordPress tables so
long imports can pause, resume, and recover.

The standalone Playground Blueprint installs the packaged plugin and opens
`Tools -> Universal Importer`. Use this bundled source path to try an import:

```text
/wordpress/wp-content/plugins/universal-wordpress-importer/examples/playground-import
```

See [universal-wordpress-importer/README.md](universal-wordpress-importer/README.md)
for features, usage, examples, limitations, and development checks.

## StillPress

`static-site-generator/` is a WordPress plugin that exports a WordPress site to
static HTML and frontend assets. It works in regular WordPress and in
WordPress Playground.

<a href="https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fraw.githubusercontent.com%2Fadamziel%2Fwp-extensions%2Fmain%2Fblueprints%2Fstatic-site-generator-browser.json">
  <img src="docs/assets/try-it-in-playground.svg" alt="Try in WordPress Playground" width="224" />
</a>

The exporter includes:

- an admin screen at `Tools -> StillPress`
- a programmatic `ssgwp_export_static_site()` API
- a WP-CLI command: `wp static-site export`
- Playground Blueprint examples for browser and CLI workflows

### Browser Playground

Open the Blueprint above to install the plugin from this repository, seed a
demo site with pages, categories, dated posts, and block content, then open
`Tools -> StillPress`.

Use the admin screen to download the static ZIP. The ZIP is the published
static site; save the full Playground site separately if you want to keep an
editable WordPress source site.

After extracting the ZIP, open `index.html` for a quick check. For the closest
preview, serve the extracted folder over HTTP:

```bash
python3 -m http.server 8080
```

Then open `http://localhost:8080/`.

### CLI Playground

From a checkout of this repository:

```bash
mkdir -p ./static-site-output
npx @wp-playground/cli@latest run-blueprint \
	--mount=./static-site-generator:/wordpress/wp-content/plugins/static-site-generator \
	--mount=./static-site-output:/exports \
	--blueprint=./blueprints/static-site-generator-cli-export.json
```

The generated ZIP is written to:

```text
./static-site-output/static-site.zip
```

Extract it and run `python3 -m http.server 8080` from the extracted folder for
a local HTTP preview.

If the Playground CLI cannot write the ZIP to the mounted output directory,
make sure the host directory is writable by the runtime:

```bash
chmod 777 ./static-site-output
```

### Regular WordPress

Copy `static-site-generator/` into `wp-content/plugins/`:

```bash
cp -R static-site-generator /path/to/wordpress/wp-content/plugins/
```

Then activate **StillPress** in `wp-admin -> Plugins`.
Open `Tools -> StillPress`, choose the export options, and download
the static ZIP.

Requirements:

- WordPress 6.5 or newer
- PHP 7.4 or newer
- PHP `zip` extension for ZIP downloads

### Regular WordPress WP-CLI

From the WordPress root, activate the plugin and run:

```bash
wp plugin activate static-site-generator
wp static-site export --output=./static-site.zip --fetch-mode=auto
wp static-site export --output-dir=./static-site --fetch-mode=auto
```

Useful options:

```bash
wp static-site export --output=./static-site.zip --url-mode=relative
wp static-site export --output-dir=./static-site --url-mode=relative
wp static-site export --output=./static-site.zip --fetch-mode=internal
wp static-site export --output=./static-site.zip --generate-sitemap --generate-robots
wp static-site export --output=./static-site.zip --report
```

Use `--fetch-mode=internal` when loopback HTTP requests are blocked or
unreliable, including many Playground environments.

Opening exported files directly with `file://` is useful for basic HTML and
CSS checks, but browsers block JavaScript ES modules from `file://` origins.
Use the local HTTP preview command above when testing interactive frontend
code such as the WordPress Interactivity API.

## Markdown Editor

`markdown-editor/` opens a directory of Markdown files in the WordPress block
editor when running in WordPress Playground.

<a href="https://playground.wordpress.net/?php=8.4&php-extension=https%3A%2F%2Fraw.githubusercontent.com%2Fadamziel%2Fwp-extensions%2Fmain%2Fmarkdown-editor%2Fsqlite-markdown-extension%2Fdist%2Fmanifest.json&blueprint-url=https%3A%2F%2Fraw.githubusercontent.com%2Fadamziel%2Fwp-extensions%2Fmain%2Fblueprints%2Fmarkdown-editor-browser.json">
  <img src="docs/assets/try-it-in-playground.svg" alt="Try in WordPress Playground" width="224" />
</a>

It includes:

- a mu-plugin that maps `wp_posts` and `wp_postmeta` to Markdown-backed SQLite
  virtual tables
- the `sqlite_markdown` PHP.wasm extension source that registers those virtual
  tables
- `php-toolkit` as a submodule for Markdown <-> block markup conversion

See [markdown-editor/README.md](markdown-editor/README.md) for usage and
development notes.

GitHub Releases publish a ready-to-run `wp-markdown-editor.zip` package with
the mu-plugin, the PHP toolkit runtime dependencies, the prebuilt PHP.wasm side
module, and a small Markdown page tree in `content/`.

### Local Playground CLI

Download the Markdown Editor release package and start Playground:

```bash
curl -fsSL https://github.com/adamziel/wp-extensions/releases/download/markdown-editor-latest/wp-markdown-editor.zip -o wp-markdown-editor.zip
rm -rf wp-markdown-editor
unzip -q wp-markdown-editor.zip

npx --yes @wp-playground/cli@latest server \
	--php=8.4 \
	--login \
	--php-extension=wp-markdown-editor/markdown-editor/sqlite-markdown-extension/dist/manifest.json \
	--mount=wp-markdown-editor/content:/markdown-root \
	--mount=wp-markdown-editor/markdown-editor:/wordpress/wp-content/mu-plugins
```

Then open:

```text
http://127.0.0.1:9400/wp-admin/edit.php?post_type=page
```

## Development checks

```bash
find static-site-generator -name '*.php' -print0 | xargs -0 -n1 php -l
php static-site-generator/tests/path-utils-test.php
php static-site-generator/tests/url-collector-test.php
php static-site-generator/tests/url-rewriter-test.php
php static-site-generator/tests/static-exporter-test.php
php static-site-generator/tests/plugin-test.php

cd universal-wordpress-importer
composer install
composer validate --no-check-publish
composer test
composer lint
composer build:release
```
