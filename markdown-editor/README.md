# Playground Markdown Editor

Edit a directory of Markdown files in the WordPress block editor.

This extension is made of three parts that should move together:

- `edit-markdown-mu-plugin.php` swaps `wp_posts` and `wp_postmeta` for
  writable SQLite virtual tables and translates Markdown to block markup at the
  editor boundary.
- `sqlite-markdown-extension/` contains the PHP.wasm side-module source that
  registers the `markdown_posts` and `markdown_postmeta` SQLite virtual tables.
- `vendor/php-toolkit` converts between Markdown and WordPress block markup.

## Setup

Use Node.js 23 or newer. External PHP.wasm extensions are JSPI-only, and the
Playground CLI enables JSPI automatically on Node.js 23+.

Initialize the toolkit submodule and install its Composer dependencies from
this repository:

```bash
git submodule update --init --recursive
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

## Playground Requirement

This extension needs Playground support that is newer than
`@wp-playground/cli@3.1.30`:

- `--php-extension`, for loading a local side-module manifest.
- PHP.wasm main modules that export SQLite's `sqlite3_auto_extension()` and
  `sqlite3_cancel_auto_extension()` symbols.

Until a Playground release includes
<https://github.com/WordPress/wordpress-playground/pull/3524>, run it from a
Playground source checkout with that PR applied:

```bash
git clone https://github.com/WordPress/wordpress-playground.git
cd wordpress-playground
gh pr checkout 3524
npm ci
npm run recompile:php:node:jspi:8.4
```

## Run With Playground CLI

The quickest path is the helper script:

```bash
markdown-editor/run-playground-cli.sh
```

It builds the `sqlite_markdown` side module, installs `php-toolkit`
dependencies, prepares a Playground checkout at `../wordpress-playground`, and
starts the Playground CLI with the required mounts.

Use environment variables to point it at existing directories:

```bash
CONTENT_DIR=~/notes \
PLAYGROUND_DIR=~/src/wordpress-playground \
markdown-editor/run-playground-cli.sh
```

The first run recompiles the Playground Node JSPI PHP build for the selected
PHP version. To skip that after you have a compatible PHP.wasm build:

```bash
RECOMPILE_PHP=0 markdown-editor/run-playground-cli.sh
```

### Manual Commands

Set these paths:

```bash
export WP_EXTENSIONS=/path/to/wp-extensions
export PLAYGROUND=/path/to/wordpress-playground
```

From the Playground checkout with PR 3524 applied:

```bash
mkdir -p "$WP_EXTENSIONS/content"
cd "$PLAYGROUND"
npx nx dev playground-cli server \
	--php=8.4 \
	--login \
	--php-extension="$WP_EXTENSIONS/markdown-editor/sqlite-markdown-extension/dist/manifest.json" \
	--mount="$WP_EXTENSIONS/content:/markdown-root" \
	--mount="$WP_EXTENSIONS/markdown-editor:/wordpress/wp-content/mu-plugins" \
	--mount="$WP_EXTENSIONS/markdown-editor:/internal/shared/markdown-editor"
```

Then open:

```text
/wp-admin/edit.php?post_type=page
```

The editor reads and writes Markdown files under `$WP_EXTENSIONS/content`.

After Playground publishes a release containing PR 3524, the same command can
use the published CLI:

```bash
npx @wp-playground/cli@latest server \
	--php=8.4 \
	--login \
	--php-extension="$WP_EXTENSIONS/markdown-editor/sqlite-markdown-extension/dist/manifest.json" \
	--mount="$WP_EXTENSIONS/content:/markdown-root" \
	--mount="$WP_EXTENSIONS/markdown-editor:/wordpress/wp-content/mu-plugins" \
	--mount="$WP_EXTENSIONS/markdown-editor:/internal/shared/markdown-editor"
```

## Notes

The `sqlite_markdown` side module calls SQLite's `sqlite3_auto_extension()` and
`sqlite3_cancel_auto_extension()` symbols. Playground PHP builds need to export
those symbols from the main module for this extension to load.
