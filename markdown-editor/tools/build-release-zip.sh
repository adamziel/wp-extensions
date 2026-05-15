#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
MARKDOWN_EDITOR_DIR="$(cd -- "$SCRIPT_DIR/.." && pwd)"
REPO_ROOT="$(cd -- "$MARKDOWN_EDITOR_DIR/.." && pwd)"
OUT_DIR="${1:-$REPO_ROOT/dist}"
PACKAGE_NAME="wp-markdown-editor"
PACKAGE_DIR="$OUT_DIR/$PACKAGE_NAME"
ZIP_FILE="$OUT_DIR/$PACKAGE_NAME.zip"
PHP_TOOLKIT_DIR="$MARKDOWN_EDITOR_DIR/vendor/php-toolkit"

require_file() {
	if [[ ! -f "$1" ]]; then
		echo "Missing required release file: $1" >&2
		exit 1
	fi
}

require_dir() {
	if [[ ! -d "$1" ]]; then
		echo "Missing required release directory: $1" >&2
		exit 1
	fi
}

require_file "$MARKDOWN_EDITOR_DIR/run-playground-cli.sh"
require_file "$MARKDOWN_EDITOR_DIR/edit-markdown-mu-plugin.php"
require_file "$MARKDOWN_EDITOR_DIR/sqlite-markdown-extension/dist/manifest.json"
require_file "$MARKDOWN_EDITOR_DIR/sqlite-markdown-extension/dist/sqlite_markdown-php8.4-jspi.so"
require_file "$PHP_TOOLKIT_DIR/vendor/autoload.php"
require_dir "$REPO_ROOT/content"

rm -rf "$PACKAGE_DIR" "$ZIP_FILE"
mkdir -p "$PACKAGE_DIR/markdown-editor/sqlite-markdown-extension" "$PACKAGE_DIR/markdown-editor/vendor"

cp "$REPO_ROOT/README.md" "$PACKAGE_DIR/README.md"
cp -R "$REPO_ROOT/content" "$PACKAGE_DIR/content"

cp "$MARKDOWN_EDITOR_DIR/README.md" "$PACKAGE_DIR/markdown-editor/README.md"
cp "$MARKDOWN_EDITOR_DIR/edit-markdown-mu-plugin.php" "$PACKAGE_DIR/markdown-editor/edit-markdown-mu-plugin.php"
cp "$MARKDOWN_EDITOR_DIR/run-playground-cli.sh" "$PACKAGE_DIR/markdown-editor/run-playground-cli.sh"
cp -R "$MARKDOWN_EDITOR_DIR/sqlite-markdown-extension/dist" "$PACKAGE_DIR/markdown-editor/sqlite-markdown-extension/dist"
cp -R "$PHP_TOOLKIT_DIR" "$PACKAGE_DIR/markdown-editor/vendor/php-toolkit"

rm -rf \
	"$PACKAGE_DIR/markdown-editor/vendor/php-toolkit/.claude" \
	"$PACKAGE_DIR/markdown-editor/vendor/php-toolkit/.devcontainer" \
	"$PACKAGE_DIR/markdown-editor/vendor/php-toolkit/.github" \
	"$PACKAGE_DIR/markdown-editor/vendor/php-toolkit/bin" \
	"$PACKAGE_DIR/markdown-editor/vendor/php-toolkit/examples" \
	"$PACKAGE_DIR/markdown-editor/vendor/php-toolkit/plugins" \
	"$PACKAGE_DIR/markdown-editor/vendor/php-toolkit/node_modules"
find "$PACKAGE_DIR/markdown-editor/vendor/php-toolkit/components" \
	\( -name Tests -o -name tests -o -name test-data \) \
	-prune -exec rm -rf {} +

cat > "$PACKAGE_DIR/run-playground-cli.sh" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
exec "$SCRIPT_DIR/markdown-editor/run-playground-cli.sh" "$@"
EOF
chmod +x "$PACKAGE_DIR/run-playground-cli.sh" "$PACKAGE_DIR/markdown-editor/run-playground-cli.sh"

find "$PACKAGE_DIR" \( -name .git -o -name node_modules \) -prune -exec rm -rf {} +
find "$PACKAGE_DIR" -name .DS_Store -delete

(
	cd "$OUT_DIR"
	zip -qr "$ZIP_FILE" "$PACKAGE_NAME"
)

echo "Wrote $ZIP_FILE"
