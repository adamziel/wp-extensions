# WP Extensions

Small WordPress extensions and experiments.

## Universal WordPress Importer

`universal-wordpress-importer/` is a WordPress plugin for durable,
resumable imports from content trees: local folders, browser-dropped folders,
zip archives, Markdown, HTML, text, EPUB, WXR, PDFs, GitHub repositories, and
WordPress REST sites.

[![Try in Playground](https://img.shields.io/badge/Try%20in-WordPress%20Playground-3858e9?style=for-the-badge&logo=wordpress)](https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fraw.githubusercontent.com%2Fadamziel%2Fwp-extensions%2Fmain%2Fblueprints%2Funiversal-wordpress-importer-demo.json)

The Playground Blueprint installs the packaged plugin and opens
`Tools -> Universal Importer`. Use this bundled source path to try an import:

```text
/wordpress/wp-content/plugins/universal-wordpress-importer/examples/playground-import
```

See [universal-wordpress-importer/README.md](universal-wordpress-importer/README.md)
for features, usage, examples, limitations, and development checks.

## Markdown Editor

`markdown-editor/` opens a directory of Markdown files in the WordPress block
editor when running in WordPress Playground.

It includes:

- a mu-plugin that maps `wp_posts` and `wp_postmeta` to Markdown-backed SQLite
  virtual tables
- the `sqlite_markdown` PHP.wasm extension source that registers those virtual
  tables
- `php-toolkit` as a submodule for Markdown <-> block markup conversion

See [markdown-editor/README.md](markdown-editor/README.md) for setup and
Playground CLI usage.

## Static Site Generator

`static-site-generator/` is a WordPress plugin that exports a WordPress site to
static HTML and frontend assets. It works in regular WordPress and in
WordPress Playground.

[<kbd>Try in Playground</kbd>](https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fraw.githubusercontent.com%2Fadamziel%2Fwp-extensions%2Fmain%2Fblueprints%2Fstatic-site-generator-browser.json)

The exporter includes:

- an admin screen at `Tools -> Static Site Generator`
- a programmatic `ssgwp_export_static_site()` API
- a WP-CLI command: `wp static-site export`
- Playground Blueprint examples for browser and CLI workflows

### Browser Playground

Open this Blueprint in the Playground webapp:

```text
https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fraw.githubusercontent.com%2Fadamziel%2Fwp-extensions%2Fmain%2Fblueprints%2Fstatic-site-generator-browser.json
```

The Blueprint installs the plugin from this repository, seeds a richer demo
site with pages, categories, dated posts, and block content, then opens
`Tools -> Static Site Generator`.

Use the admin screen to download the static ZIP. The ZIP is the published
static site; save the full Playground site separately if you want to keep an
editable WordPress source site.

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

Then activate **Playground Static Site Generator** in `wp-admin -> Plugins`.
Open `Tools -> Static Site Generator`, choose the export options, and download
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
```

Useful options:

```bash
wp static-site export --output=./static-site.zip --url-mode=relative
wp static-site export --output=./static-site.zip --max-pages=1000
wp static-site export --output=./static-site.zip --fetch-mode=internal
wp static-site export --output=./static-site.zip --skip-uploads --skip-plugins
```

Use `--fetch-mode=internal` when loopback HTTP requests are blocked or
unreliable, including many Playground environments.

### Development Checks

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
