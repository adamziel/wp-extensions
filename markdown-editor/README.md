# Playground Markdown Editor

Edit a directory of Markdown files in the WordPress block editor.

## Usage

### Try The Demo

Fetch only the Markdown editor and sample content:

```bash
git clone --filter=blob:none --sparse https://github.com/adamziel/wp-extensions.git wp-markdown-editor
cd wp-markdown-editor
git sparse-checkout set markdown-editor content
markdown-editor/run-playground-cli.sh
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

Point the runner at any directory of Markdown files:

```bash
CONTENT_DIR=~/notes markdown-editor/run-playground-cli.sh
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
PORT=9410 markdown-editor/run-playground-cli.sh
PLAYGROUND_CLI_PACKAGE=@wp-playground/cli@3.1.33 markdown-editor/run-playground-cli.sh
```

Use `@wp-playground/cli@3.1.33` or newer. Older Playground packages do not
export all PHP.wasm symbols needed by this editor.

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
Playground. On first run, it prepares the local toolkit checkout, uses the
prebuilt `sqlite_markdown` PHP.wasm side module when present, and starts the
published Playground CLI with the required mounts. If the side-module artifact
is missing, the runner builds it with `@php-wasm/compile-extension`.

### Requirements

- Node.js 23 or newer. External PHP.wasm extensions are JSPI-only, and the
  Playground CLI enables JSPI automatically on Node.js 23+.
- Composer, for `vendor/php-toolkit`.
- Docker, for `@php-wasm/compile-extension` builds.
- `@wp-playground/cli@3.1.33` or newer.

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
	--mount-dir "$WP_EXTENSIONS/content" /markdown-root \
	--mount-dir "$WP_EXTENSIONS/markdown-editor" /wordpress/wp-content/mu-plugins \
	--mount-dir "$WP_EXTENSIONS/markdown-editor" /internal/shared/markdown-editor
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
