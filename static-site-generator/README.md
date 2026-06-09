# StillPress

<a href="https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fraw.githubusercontent.com%2Fadamziel%2Fwp-extensions%2Fmain%2Fblueprints%2Fstatic-site-generator-browser.json" target="_blank" rel="noopener noreferrer">
  <img src="assets/try-it-in-playground.svg" alt="Try it in WordPress Playground" width="260" height="64" />
</a>

Export a WordPress site to static HTML and frontend assets. The plugin works
in regular WordPress and in WordPress Playground, and it can export from the
admin UI or from WP-CLI.

The exporter includes:

- an admin screen at `Tools -> StillPress`
- a reload-safe admin progress bar with current action, percent complete, and
  an export log
- a programmatic `ssgwp_export_static_site()` API
- a WP-CLI command: `wp static-site export`
- Playground Blueprint examples for browser and CLI workflows

## Browser Playground

<a href="https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fraw.githubusercontent.com%2Fadamziel%2Fwp-extensions%2Fmain%2Fblueprints%2Fstatic-site-generator-browser.json" target="_blank" rel="noopener noreferrer">
  <img src="assets/try-it-in-playground.svg" alt="Try it in WordPress Playground" width="260" height="64" />
</a>

The Blueprint installs this plugin, seeds a richer demo site with pages,
categories, dated posts, and block content, then opens
`Tools -> StillPress`.

Use the admin screen to download the static ZIP. The ZIP is the published
static site; save the full Playground site separately if you want to keep an
editable WordPress source site.

After extracting the ZIP, open `index.html` for a quick check. For the closest
preview, serve the extracted folder over HTTP so browser module scripts can
run:

```bash
python3 -m http.server 8080
```

Then open `http://localhost:8080/`.

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

Extract it and use the same local HTTP preview command from the extracted
folder:

```bash
python3 -m http.server 8080
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

Then activate **StillPress** in `wp-admin -> Plugins`.
Open `Tools -> StillPress`, choose any extra files you want, and
download the static ZIP. The exporter includes required frontend assets and
linked site pages automatically. Hosting settings can stay on the default
unless your hosting provider specifically needs root-relative or full URLs.

Requirements:

- WordPress 6.5 or newer
- PHP 7.4 or newer
- PHP `zip` extension for ZIP downloads

## Regular WordPress WP-CLI

From the WordPress root, activate the plugin and run:

```bash
wp plugin activate static-site-generator
wp static-site export --output=./static-site.zip --fetch-mode=auto
wp static-site export --output-dir=./static-site --fetch-mode=auto
```

Useful options:

```bash
wp static-site export --output=./static-site.zip --url-mode=relative # portable ZIP/subfolder links
wp static-site export --output=./static-site.zip --url-mode=root     # links start at /
wp static-site export --output=./static-site.zip --url-mode=absolute # links use the current site URL
wp static-site export --output-dir=./static-site --url-mode=relative # write files directly
wp static-site export --output=./static-site.zip --fetch-mode=internal
wp static-site export --output=./static-site.zip --generate-sitemap --generate-robots
wp static-site export --output=./static-site.zip --report
wp static-site export --output-dir=./static-site --include-playground-admin
wp static-site export --output-dir=./static-site --include-playground-source-state
wp static-site export --output-dir=./static-site --include-playground-source-state --playground-source-wxr-url='https://example.com/signed/site-content.wxr' --playground-source-expires-at='2026-06-30T12:45:00Z'
wp static-site export --output-dir=./static-site --include-cloudflare-publish
```

Use `--fetch-mode=internal` when loopback HTTP requests are blocked or
unreliable, including many Playground environments.

`--include-playground-admin` adds a static `/wp-admin/index.html` handoff page,
`wp-admin/playground-blueprint.json`, and
`wp-admin/playground-source-manifest.json`. Without source-state artifacts, the
handoff keeps using a `blueprint-url` that points at
`wp-admin/playground-blueprint.json` and records a deterministic manifest
pointer; it does not persist a full editable source-site bundle by itself.

`--include-playground-source-state` writes owner-only restore metadata under
`_playground-source/`, including `source-state.json`, `site-content.wxr`, and
`playground-blueprint-bundle.zip`. When the source site has a readable
Playground SQLite database at `wp-content/database/.ht.sqlite`, the export also
writes `_playground-source/wordpress-files.zip`. That ZIP contains top-level
`wp-content/...` entries for the SQLite database, active plugin files, active
theme files, and uploads, while excluding symlink targets, cache/temp/export
operational directories, and obvious credential files such as `.env`, `*.pem`,
tokens, credentials, and private keys.

With a captured SQLite database, `playground-blueprint-bundle.zip` becomes a
full-site SQLite restore bundle. Its root `blueprint.json` uses
`importWordPressFiles` with the bundled `/wordpress-files.zip` resource, then
writes the restored-admin context option and lands in `Tools -> StillPress`.
That restores `wp-content` plus the SQLite database, including plugins, themes,
uploads, content, and database-stored settings. It does not run `importWxr`
after the SQLite restore, because importing WXR on top of the restored database
could duplicate content. The WXR file is still emitted as a fallback artifact.

If no readable SQLite database exists, the owner-only bundle stays WXR-only: it
has root `blueprint.json`, bundled WXR content at `content/site-content.wxr`,
and source-state metadata. Its Blueprint imports the WXR with a bundled
resource path, so it does not need the separate WXR URL used by the web handoff.
This WXR fallback restores content only; it does not restore plugin settings,
the full database, users, secrets, uploads outside WXR references, or runtime
configuration. Non-SQLite/MySQL source sites use this content-only fallback
until a database dump and restore path is implemented.

The bundle is always owner-only source material. It can be hosted intentionally
as a WordPress Playground `?blueprint-url=` bundle URL, but do not publish it
blindly with the public static site. To intentionally host it, serve
`_playground-source/playground-blueprint-bundle.zip` from a public, signed, or
private URL that Playground can fetch, then open:

```text
https://playground.wordpress.net/?blueprint-url=<encoded bundle URL>
```

The generated artifacts are provenance and restore material, not an
authentication, credential, or redeploy authorization system.

To run a live WordPress Playground restore smoke against an owner-only bundle:

```bash
php static-site-generator/tools/smoke-playground-source-bundle.php --skip-if-unavailable ./static-site
```

The input can be an export directory containing
`_playground-source/playground-blueprint-bundle.zip`, the bundle ZIP itself, or
an unpacked bundle directory with a root `blueprint.json`. The smoke runner
copies the bundle to a temporary directory, adds a bundled assertion file, then
injects `writeFile` and `wp-cli` steps that run `wp eval-file` against the
restored site. It then runs:

```bash
npx --yes @wp-playground/cli@latest run-blueprint --mount=<temp-dir>:/ssgwp-smoke --blueprint=<temp-blueprint> --blueprint-may-read-adjacent-files
```

Use `--dry-run` to verify the injected Blueprint and command without launching
Playground. Temporary bundles are removed by default; add `--keep-bundle` when
you need to inspect the generated smoke bundle. You can pin runtime inputs with
`--playground-cli=<package>`, `--wp=<version>`, and `--php=<version>`.

Live smoke prerequisites are the PHP `zip` extension, Node.js/npm/npx, and a
network path that can fetch and run the requested Playground CLI package. With
`--skip-if-unavailable`, missing Node/npx, unsupported Node versions, network
fetch failures, and Playground CLI runtime failures print `SKIP` and exit 0.
Smoke assertion failures still fail because they print the
`SSGWP_PLAYGROUND_SOURCE_BUNDLE_SMOKE_FAIL` marker. Pass
`--no-skip-if-unavailable` when infrastructure failures should fail the command.

The default PHP test for this tool is a contract test and does not require
network access:

```bash
php static-site-generator/tests/playground-source-bundle-smoke-test.php
```

Set `SSGWP_RUN_PLAYGROUND_SMOKE=1` for that test to also attempt the optional
live Playground CLI smoke with skip-if-unavailable behavior.

This option also includes the existing static `/wp-admin/` handoff so
`wp-admin/playground-source-manifest.json` can point to the source artifacts,
record the WXR SHA-256 hash, and record the Blueprint bundle path/hash. The
`/wp-admin/index.html` handoff remains an inline Blueprint URL flow. By default,
it computes the WXR URL at runtime from
`../_playground-source/site-content.wxr`. If your workflow serves the WXR
through a separate public, signed, or private URL, pass
`--playground-source-wxr-url=<url>`; the inline Playground Blueprint will use
that explicit URL instead for WXR fallback and web handoff flows. The owner-only
bundle remains bundled-resource mode and does not include that explicit WXR URL;
full-site SQLite metadata also avoids persisting provided WXR URLs because the
restore uses bundled WordPress files. `--playground-source-expires-at=<timestamp>`
records optional access-expiry metadata for the source URL. Accepted timestamps
are conservative ISO-style values such as `2026-06-30T12:45:00Z` or
`2026-06-30T12:45:00+00:00`; invalid values are ignored rather than treated as
authoritative.

The source-state JSON and handoff manifest record `wxr_url_mode`, the effective
provided WXR URL when one was supplied for WXR fallback, owner-only access
policy notes, and a redeploy authorization note. They also record the Blueprint
bundle path, hash, mode, content-only or full-site status, and the
`wordpress-files.zip` path/hash/status when a SQLite snapshot was captured. This
is policy/provenance metadata and handoff URL selection only. It is not an
authentication system, does not store deploy credentials, owner identity, or
authorization tokens, and does not authorize a redeploy by itself. A redeploy is
still a local/generated workflow that must be run by an authorized owner or
operator with credentials. Treat `_playground-source/` as sensitive because it
can expose editable source content. Do not blindly expose it on generic hosting;
use intentional public, signed, or private URLs for owner-only restore workflows.

When a source-state handoff opens the restored Playground admin, the Blueprint
writes a non-secret `ssgwp_playground_source_handoff` option. `Tools ->
StillPress` uses that option to show compact context for the edit, export, and
redeploy path: the site came from a static export source-state handoff, WXR
restore was content-only or SQLite full-site restore imported wp-content plus
the SQLite database, and owners can edit content/plugins before exporting a new
static ZIP. The restored option records WXR mode/hash, optional source access
expiry metadata, full-site snapshot status/hash when present, whether Cloudflare
publish artifacts were included, and the fact that redeploy requires
owner/operator credentials outside the export. It intentionally does not store
Cloudflare credentials, authorization tokens, owner identity, or the explicit
effective WXR URL. Selecting the Cloudflare Workers publish contract only
generates local deploy/redeploy artifacts; it does not automatically deploy to
Cloudflare.

`--include-cloudflare-publish` adds a deterministic `_cloudflare-publish/`
deploy package. The package contains `wrangler.jsonc`, `cloudflare-worker.js`,
`package.json`, `cloudflare-deploy-check.mjs`, `cloudflare-publish.json`,
`CLOUDFLARE-WORKERS.md`, and a `site/` directory copied from the static export.
Wrangler is configured with `assets.directory` set to `./site`, so the
Worker/config/manifest/docs/workflow files are not served as static assets.
These files are local-only and do not call Cloudflare during export. The
generated package scripts support offline validation, credential presence
validation, Wrangler dry-run deploy, real deploy, versions list, deployments
list, and rollback. A real deploy requires `CLOUDFLARE_ACCOUNT_ID` and
`CLOUDFLARE_API_TOKEN`; the token needs Account `Workers Scripts:Edit`, plus
Zone `Workers Routes:Edit` and `Zone:Read` when using a route or custom domain.
Add Zone `DNS:Edit` only when your deploy automation must create or change DNS
records separately. The generated contract records the deploy workflow commands,
served asset file count, largest served asset size, and current Workers Free
limits used by this slice: 100,000 requests/day, 10 ms CPU/request, 128 MB
memory/isolate, 100 Workers per account, 20,000 static asset files per Worker
version, and 25 MiB per static asset file. Running generated Wrangler scripts
may call Cloudflare; export generation and the offline deploy check do not.

To smoke-test the generated Cloudflare package without calling Cloudflare, run:

```bash
php static-site-generator/tools/smoke-cloudflare-deploy-package.php --offline ./static-site
```

The input can be the export directory or the `_cloudflare-publish/` directory.
The smoke tool always runs the generated
`node cloudflare-deploy-check.mjs --offline` package validation first. Use
`--credentials` to also require `CLOUDFLARE_ACCOUNT_ID` and
`CLOUDFLARE_API_TOKEN` in the current environment, without printing their
values or reading credential files:

```bash
php static-site-generator/tools/smoke-cloudflare-deploy-package.php --credentials --skip-if-missing-credentials ./static-site
```

Use `--dry-run` to require credentials and run
`npx wrangler deploy --config wrangler.jsonc --dry-run` from the deploy
package. Use `--skip-if-missing-credentials` in CI or local environments where
Cloudflare credentials are optional; missing `CLOUDFLARE_ACCOUNT_ID` or
`CLOUDFLARE_API_TOKEN` prints `SKIP` and exits 0. A real deploy is never run
unless both `--deploy` and `--confirm-deploy` are present:

```bash
php static-site-generator/tools/smoke-cloudflare-deploy-package.php --deploy --confirm-deploy ./static-site
```

The current no-credential environment can pass local/offline validation and
skip credentialed deploy or dry-run checks clearly. Default tests remain
network-free; set `SSGWP_RUN_CLOUDFLARE_DRY_RUN=1` for the optional Wrangler
dry-run smoke when credentials are present.

For generic static hosting, do not blindly upload every generated operational
directory. `_cloudflare-publish/` is a deploy package, not the public site root;
the Cloudflare public assets are in `_cloudflare-publish/site/`.
`_playground-source/` is owner-only restore material and is not copied into
`_cloudflare-publish/site/`; publish the WXR or Blueprint bundle only through an
intentional public, signed, or private URL when an owner/operator restore
workflow needs Playground to fetch it.

To test a ZIP export locally, extract the ZIP and run this command from the
extracted folder. With `--output-dir`, run it from the directory you exported:

```bash
python3 -m http.server 8080
```

Then open `http://localhost:8080/`. Opening files directly with `file://` is
useful for basic HTML and CSS checks, but browsers block JavaScript ES modules
from `file://` origins. That affects interactive frontend code such as the
WordPress Interactivity API.

## Development Checks

```bash
find static-site-generator -name '*.php' -print0 | xargs -0 -n1 php -l
php static-site-generator/tests/path-utils-test.php
php static-site-generator/tests/url-collector-test.php
php static-site-generator/tests/url-rewriter-test.php
php static-site-generator/tests/static-exporter-test.php
php static-site-generator/tests/plugin-test.php
php static-site-generator/tests/cloudflare-deploy-smoke-test.php
```
