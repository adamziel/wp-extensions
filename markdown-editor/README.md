# Playground Markdown Editor

Edit a directory of Markdown files in the WordPress block editor.

## Usage

### Try The Demo

#### Browser Playground

Open the Markdown Editor demo in the WordPress Playground website:

```text
https://playground.wordpress.net/?php=8.4&php-extension=https%3A%2F%2Fraw.githubusercontent.com%2Fadamziel%2Fwp-extensions%2Fmain%2Fmarkdown-editor%2Fsqlite-markdown-extension%2Fdist%2Fmanifest.json&blueprint-url=https%3A%2F%2Fraw.githubusercontent.com%2Fadamziel%2Fwp-extensions%2Fmain%2Fblueprints%2Fmarkdown-editor-browser.json
```

This browser demo uses the published `sqlite_markdown` PHP.wasm extension and
loads the sample Markdown tree into Playground's temporary browser filesystem.
Changes made in the editor stay inside that Playground session.

#### Local Playground CLI

Download the GitHub Release package and start Playground:

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

The demo loads a small page tree from `content/`:

```text
content/
|-- 101-home.md
|-- 200-field-notes/
|   |-- index.md
|   `-- 201-first-draft.md
`-- 300-about-the-demo.md
```

Open **Pages** in wp-admin, edit one of the pages, save it, and inspect the
corresponding Markdown file on disk.

### Use Your Own Markdown Directory

Point the release runner at any directory of Markdown files:

```bash
CONTENT_DIR=~/notes wp-markdown-editor/run-playground-cli.sh
```

The editor expects each page file to have front matter and a numeric storage ID
in the file or directory name:

```text
101-home.md
200-field-notes/index.md
200-field-notes/201-first-draft.md
```

Minimal page file:

```markdown
---
post_title = "Home"
post_name = "home"
post_status = "publish"
post_type = "page"
post_date_gmt = "2026-05-15 08:00:00"
post_modified_gmt = "2026-05-15 08:00:00"
---
# Home

Hello from Markdown.
```

### Useful Options

```bash
PORT=9410 wp-markdown-editor/run-playground-cli.sh
PLAYGROUND_CLI_PACKAGE=@wp-playground/cli@3.1.33 wp-markdown-editor/run-playground-cli.sh
```

Use `@wp-playground/cli@3.1.33` or newer. Older Playground packages do not
export all PHP.wasm symbols needed by this editor.

The bundled `wp-markdown-editor/run-playground-cli.sh` wrapper runs the same
Playground CLI command and adds validation for Node.js, the Playground CLI
`--php-extension` option, and missing release artifacts.

## Development

### How It Works

This extension is made of three parts that should move together:

- `edit-markdown-mu-plugin.php` swaps `wp_posts` and `wp_postmeta` for
  writable SQLite virtual tables and translates Markdown to block markup at the
  editor boundary.
- `sqlite-markdown-extension/` contains the PHP.wasm side-module source that
  registers the `markdown_posts` and `markdown_postmeta` SQLite virtual tables.
- `vendor/php-toolkit` converts between Markdown and WordPress block markup.

The runner uses published npm packages only. It does not clone WordPress
Playground. The GitHub Release zip includes the local toolkit runtime
dependencies and prebuilt `sqlite_markdown` PHP.wasm side module, so the usage
flow does not need Composer, Docker, or a repository checkout. In a development
checkout, the runner can still prepare the toolkit checkout and build the side
module when those release artifacts are missing.

### Requirements

- Node.js 23 or newer. External PHP.wasm extensions are JSPI-only, and the
  Playground CLI enables JSPI automatically on Node.js 23+.
- Composer, for `vendor/php-toolkit`.
- Docker, for `@php-wasm/compile-extension` builds.
- `@wp-playground/cli@3.1.33` or newer.

### Build The Release Zip

The release package includes the runtime files users need without a Git
checkout:

```bash
git submodule update --init --recursive markdown-editor/vendor/php-toolkit
composer install --no-dev --prefer-dist --no-interaction \
	-d markdown-editor/vendor/php-toolkit
markdown-editor/tools/build-release-zip.sh
```

The output is:

```text
dist/wp-markdown-editor.zip
```

### Build The Side Module

Initialize the toolkit submodule and install its Composer dependencies:

```bash
git submodule update --init --recursive markdown-editor/vendor/php-toolkit
composer install --no-dev --prefer-dist --no-interaction \
	-d markdown-editor/vendor/php-toolkit
```

Build the `sqlite_markdown` PHP.wasm extension artifacts:

```bash
npx --yes @php-wasm/compile-extension@latest \
	--source ./markdown-editor/sqlite-markdown-extension/src \
	--name sqlite_markdown \
	--php-versions 8.4 \
	--out ./markdown-editor/sqlite-markdown-extension/dist
```

The output directory must contain:

```text
markdown-editor/sqlite-markdown-extension/dist/manifest.json
markdown-editor/sqlite-markdown-extension/dist/sqlite_markdown-php8.4-jspi.so
```

### Manual Playground CLI Command

Set this path:

```bash
export WP_EXTENSIONS=/path/to/wp-extensions
```

Run the published Playground CLI:

```bash
npx --yes @wp-playground/cli@latest server \
	--php=8.4 \
	--login \
	--php-extension="$WP_EXTENSIONS/markdown-editor/sqlite-markdown-extension/dist/manifest.json" \
	--mount="$WP_EXTENSIONS/content:/markdown-root" \
	--mount="$WP_EXTENSIONS/markdown-editor:/wordpress/wp-content/mu-plugins"
```

Then open:

```text
http://127.0.0.1:9400/wp-admin/edit.php?post_type=page
```

### Playground Compatibility

This extension needs Playground support included in `@wp-playground/cli@3.1.33`
or newer:

- `--php-extension`, for loading a local side-module manifest.
- PHP.wasm main modules that export SQLite's `sqlite3_auto_extension()` and
  `sqlite3_cancel_auto_extension()` symbols.
- PHP.wasm main modules that export filesystem helpers used by side modules
  that read and write mounted directories, including `opendir()`, `readdir()`,
  `closedir()`, `stat()`, `mkdir()`, `rename()`, `rmdir()`, and `unlink()`.

Check the installed CLI support:

```bash
npm view @wp-playground/cli version
npx --yes @wp-playground/cli@latest server --help | grep -- --php-extension
```
