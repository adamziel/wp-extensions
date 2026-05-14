#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd -- "$SCRIPT_DIR/.." && pwd)"

PHP_VERSION="${PHP_VERSION:-8.4}"
CONTENT_DIR="${CONTENT_DIR:-$REPO_ROOT/content}"
PLAYGROUND_DIR="${PLAYGROUND_DIR:-$(cd -- "$REPO_ROOT/.." && pwd)/wordpress-playground}"
PORT="${PORT:-9400}"
BUILD_EXTENSION="${BUILD_EXTENSION:-1}"
INSTALL_TOOLKIT="${INSTALL_TOOLKIT:-1}"
RECOMPILE_PHP="${RECOMPILE_PHP:-auto}"

EXTENSION_SOURCE="$SCRIPT_DIR/sqlite-markdown-extension/src"
EXTENSION_DIST="$SCRIPT_DIR/sqlite-markdown-extension/dist"
EXTENSION_MANIFEST="$EXTENSION_DIST/manifest.json"
PHP_TOOLKIT_DIR="$SCRIPT_DIR/vendor/php-toolkit"
PHP_TOOLKIT_AUTOLOAD="$PHP_TOOLKIT_DIR/vendor/autoload.php"
RECOMPILE_MARKER="$PLAYGROUND_DIR/.cache/markdown-editor/php-node-jspi-$PHP_VERSION"

usage() {
	cat <<'USAGE'
Run the Playground Markdown Editor locally.

Environment variables:
  PLAYGROUND_DIR   WordPress Playground checkout. Defaults to ../wordpress-playground.
                   If it does not exist, this script clones trunk there.
  CONTENT_DIR      Markdown directory to edit. Defaults to ./content.
  PHP_VERSION      PHP version for the side module and Playground. Defaults to 8.4.
  PORT             Playground CLI port. Defaults to 9400.
  BUILD_EXTENSION  Build sqlite_markdown side module before running. Defaults to 1.
  INSTALL_TOOLKIT  Run composer install in vendor/php-toolkit if needed. Defaults to 1.
  RECOMPILE_PHP    Recompile Playground Node JSPI PHP build.
                   Defaults to auto, which recompiles once and writes a marker.
                   Set to 0 to skip, or 1 to force.

Examples:
  markdown-editor/run-playground-cli.sh
  CONTENT_DIR=~/notes PLAYGROUND_DIR=~/src/wordpress-playground markdown-editor/run-playground-cli.sh
  RECOMPILE_PHP=0 markdown-editor/run-playground-cli.sh
USAGE
}

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
	usage
	exit 0
fi

require_command() {
	if ! command -v "$1" >/dev/null 2>&1; then
		echo "Missing required command: $1" >&2
		exit 1
	fi
}

require_command git
require_command node
require_command npm
require_command npx

NODE_MAJOR="$(node -p 'Number(process.versions.node.split(".")[0])')"
if (( NODE_MAJOR < 23 )); then
	cat >&2 <<EOF
Node.js 23 or newer is required.

External PHP.wasm extensions are JSPI-only. The Playground CLI respawns itself
with --experimental-wasm-jspi on Node.js 23+.
EOF
	exit 1
fi

if [[ ! -d "$PLAYGROUND_DIR/.git" ]]; then
	echo "Cloning WordPress Playground into $PLAYGROUND_DIR"
	git clone https://github.com/WordPress/wordpress-playground.git "$PLAYGROUND_DIR"
fi

if [[ ! -f "$PLAYGROUND_DIR/package.json" ]]; then
	echo "PLAYGROUND_DIR does not look like a WordPress Playground checkout: $PLAYGROUND_DIR" >&2
	exit 1
fi

mkdir -p "$CONTENT_DIR" "$EXTENSION_DIST" "$(dirname "$RECOMPILE_MARKER")"

if [[ "$INSTALL_TOOLKIT" != "0" && ! -f "$PHP_TOOLKIT_AUTOLOAD" ]]; then
	require_command composer
	echo "Installing php-toolkit Composer dependencies"
	git -C "$REPO_ROOT" submodule update --init --recursive markdown-editor/vendor/php-toolkit
	composer install --no-dev --prefer-dist --no-interaction -d "$PHP_TOOLKIT_DIR"
fi

if [[ "$BUILD_EXTENSION" != "0" ]]; then
	echo "Building sqlite_markdown PHP.wasm side module for PHP $PHP_VERSION"
	npx --yes @php-wasm/compile-extension@latest \
		--source "$EXTENSION_SOURCE" \
		--name sqlite_markdown \
		--php-versions "$PHP_VERSION" \
		--out "$EXTENSION_DIST"
fi

if [[ ! -f "$EXTENSION_MANIFEST" ]]; then
	echo "Missing extension manifest: $EXTENSION_MANIFEST" >&2
	echo "Run with BUILD_EXTENSION=1 or build the side module manually." >&2
	exit 1
fi

if [[ ! -d "$PLAYGROUND_DIR/node_modules" ]]; then
	echo "Installing WordPress Playground npm dependencies"
	npm ci --prefix "$PLAYGROUND_DIR"
fi

if [[ "$RECOMPILE_PHP" == "1" || ( "$RECOMPILE_PHP" == "auto" && ! -f "$RECOMPILE_MARKER" ) ]]; then
	echo "Recompiling Playground Node JSPI PHP $PHP_VERSION"
	(
		cd "$PLAYGROUND_DIR"
		npm run "recompile:php:node:jspi:${PHP_VERSION}"
	)
	date -u +"%Y-%m-%dT%H:%M:%SZ" > "$RECOMPILE_MARKER"
fi

cat <<EOF

Starting Playground Markdown Editor

  Playground: $PLAYGROUND_DIR
  Markdown:   $CONTENT_DIR
  Extension:  $EXTENSION_MANIFEST
  URL:        http://127.0.0.1:$PORT/wp-admin/edit.php?post_type=page

EOF

(
	cd "$PLAYGROUND_DIR"
	npx nx dev playground-cli server \
		--php="$PHP_VERSION" \
		--port="$PORT" \
		--login \
		--php-extension="$EXTENSION_MANIFEST" \
		--mount-dir "$CONTENT_DIR" /markdown-root \
		--mount-dir "$SCRIPT_DIR" /wordpress/wp-content/mu-plugins \
		--mount-dir "$SCRIPT_DIR" /internal/shared/markdown-editor
)
