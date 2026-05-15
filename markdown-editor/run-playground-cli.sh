#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd -- "$SCRIPT_DIR/.." && pwd)"

PHP_VERSION="${PHP_VERSION:-8.4}"
CONTENT_DIR="${CONTENT_DIR:-$REPO_ROOT/content}"
PLAYGROUND_CLI_PACKAGE="${PLAYGROUND_CLI_PACKAGE:-@wp-playground/cli@latest}"
PORT="${PORT:-9400}"
BUILD_EXTENSION="${BUILD_EXTENSION:-auto}"
INSTALL_TOOLKIT="${INSTALL_TOOLKIT:-1}"

EXTENSION_SOURCE="$SCRIPT_DIR/sqlite-markdown-extension/src"
EXTENSION_DIST="$SCRIPT_DIR/sqlite-markdown-extension/dist"
EXTENSION_MANIFEST="$EXTENSION_DIST/manifest.json"
PHP_TOOLKIT_DIR="$SCRIPT_DIR/vendor/php-toolkit"
PHP_TOOLKIT_AUTOLOAD="$PHP_TOOLKIT_DIR/vendor/autoload.php"

usage() {
	cat <<'USAGE'
Run the Playground Markdown Editor locally.

Environment variables:
  PLAYGROUND_CLI_PACKAGE
                   Published Playground CLI package to run with npx.
                   Defaults to @wp-playground/cli@latest.
  CONTENT_DIR      Markdown directory to edit. Defaults to ./content.
  PHP_VERSION      PHP version for the side module and Playground. Defaults to 8.4.
  PORT             Playground CLI port. Defaults to 9400.
  BUILD_EXTENSION  Build sqlite_markdown side module before running.
                   Defaults to auto, which builds only when dist is missing.
                   Set to 0 to skip, or 1 to force.
  INSTALL_TOOLKIT  Run composer install in vendor/php-toolkit if needed. Defaults to 1.

Examples:
  markdown-editor/run-playground-cli.sh
  CONTENT_DIR=~/notes markdown-editor/run-playground-cli.sh
  PLAYGROUND_CLI_PACKAGE=@wp-playground/cli@3.1.33 markdown-editor/run-playground-cli.sh
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

CLI_HELP="$(npx --yes "$PLAYGROUND_CLI_PACKAGE" server --help 2>&1 || true)"
if ! grep -q -- "--php-extension" <<<"$CLI_HELP"; then
	cat >&2 <<EOF
$PLAYGROUND_CLI_PACKAGE does not expose the required --php-extension option.

This runner intentionally uses published npm packages only and will not clone
WordPress Playground. Publish a Playground CLI release that includes support for
loading PHP.wasm side modules, then rerun this script. You can test a specific
published version with:

  PLAYGROUND_CLI_PACKAGE=@wp-playground/cli@<version> $0
EOF
	exit 1
fi

mkdir -p "$CONTENT_DIR" "$EXTENSION_DIST"

if [[ "$INSTALL_TOOLKIT" != "0" && ! -f "$PHP_TOOLKIT_AUTOLOAD" ]]; then
	require_command git
	require_command composer
	echo "Installing php-toolkit Composer dependencies"
	git -C "$REPO_ROOT" submodule update --init --recursive markdown-editor/vendor/php-toolkit
	composer install --no-dev --prefer-dist --no-interaction -d "$PHP_TOOLKIT_DIR"
fi

if [[ "$BUILD_EXTENSION" == "1" || ( "$BUILD_EXTENSION" == "auto" && ! -f "$EXTENSION_MANIFEST" ) ]]; then
	echo "Building sqlite_markdown PHP.wasm side module for PHP $PHP_VERSION"
	npx --yes @php-wasm/compile-extension@latest \
		--source "$EXTENSION_SOURCE" \
		--name sqlite_markdown \
		--php-versions "$PHP_VERSION" \
		--out "$EXTENSION_DIST"
elif [[ "$BUILD_EXTENSION" != "0" && "$BUILD_EXTENSION" != "auto" ]]; then
	echo "Invalid BUILD_EXTENSION value: $BUILD_EXTENSION" >&2
	echo "Use 0, 1, or auto." >&2
	exit 1
fi

if [[ ! -f "$EXTENSION_MANIFEST" ]]; then
	echo "Missing extension manifest: $EXTENSION_MANIFEST" >&2
	echo "Run with BUILD_EXTENSION=1 or build the side module manually." >&2
	exit 1
fi

cat <<EOF

Starting Playground Markdown Editor

  CLI package: $PLAYGROUND_CLI_PACKAGE
  Markdown:    $CONTENT_DIR
  Extension:   $EXTENSION_MANIFEST
  URL:         http://127.0.0.1:$PORT/wp-admin/edit.php?post_type=page

EOF

npx --yes "$PLAYGROUND_CLI_PACKAGE" server \
	--php="$PHP_VERSION" \
	--port="$PORT" \
	--login \
	--php-extension="$EXTENSION_MANIFEST" \
	--mount="$CONTENT_DIR:/markdown-root" \
	--mount="$SCRIPT_DIR:/wordpress/wp-content/mu-plugins"
