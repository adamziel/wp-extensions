# Playground Static Site Generator

<a href="https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fraw.githubusercontent.com%2Fadamziel%2Fwp-extensions%2Fmain%2Fblueprints%2Fstatic-site-generator-browser.json" target="_blank" rel="noopener noreferrer">
  <img src="https://raw.githubusercontent.com/adamziel/playground-preview/refs/heads/trunk/assets/playground-preview-button.svg" alt="Open WordPress Playground Preview" width="220" height="57" />
</a>

Export a WordPress site to static HTML and frontend assets. The plugin works
in regular WordPress and in WordPress Playground, and it can export from the
admin UI or from WP-CLI.

The exporter includes:

- an admin screen at `Tools -> Static Site Generator`
- a reload-safe admin progress bar with current action, percent complete, and
  an export log
- a programmatic `ssgwp_export_static_site()` API
- a WP-CLI command: `wp static-site export`
- Playground Blueprint examples for browser and CLI workflows

## Browser Playground

<a href="https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fraw.githubusercontent.com%2Fadamziel%2Fwp-extensions%2Fmain%2Fblueprints%2Fstatic-site-generator-browser.json" target="_blank" rel="noopener noreferrer">
  <img src="https://raw.githubusercontent.com/adamziel/playground-preview/refs/heads/trunk/assets/playground-preview-button.svg" alt="Open WordPress Playground Preview" width="220" height="57" />
</a>

The Blueprint installs this plugin, seeds a richer demo site with pages,
categories, dated posts, and block content, then opens
`Tools -> Static Site Generator`.

Use the admin screen to download the static ZIP. The ZIP is the published
static site; save the full Playground site separately if you want to keep an
editable WordPress source site.

## CLI Playground

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

## Regular WordPress

Copy this plugin directory into `wp-content/plugins/`:

```bash
cp -R static-site-generator /path/to/wordpress/wp-content/plugins/
```

Then activate **Playground Static Site Generator** in `wp-admin -> Plugins`.
Open `Tools -> Static Site Generator`, choose the link format and artifact
extras, and download the static ZIP. The exporter includes required frontend
assets and linked site pages automatically.

Requirements:

- WordPress 6.5 or newer
- PHP 7.4 or newer
- PHP `zip` extension for ZIP downloads

## Regular WordPress WP-CLI

From the WordPress root, activate the plugin and run:

```bash
wp plugin activate static-site-generator
wp static-site export --output=./static-site.zip --fetch-mode=auto
```

Useful options:

```bash
wp static-site export --output=./static-site.zip --url-mode=relative
wp static-site export --output=./static-site.zip --fetch-mode=internal
wp static-site export --output=./static-site.zip --generate-sitemap --generate-robots
wp static-site export --output=./static-site.zip --no-report
```

Use `--fetch-mode=internal` when loopback HTTP requests are blocked or
unreliable, including many Playground environments.

## Development Checks

```bash
find static-site-generator -name '*.php' -print0 | xargs -0 -n1 php -l
php static-site-generator/tests/path-utils-test.php
php static-site-generator/tests/url-collector-test.php
php static-site-generator/tests/url-rewriter-test.php
php static-site-generator/tests/static-exporter-test.php
php static-site-generator/tests/plugin-test.php
```
