# Release Packaging

Release archives should package the source `indexer` directory as the public
`language-fts` WordPress plugin root. The reusable FTS engine ships through Composer under `vendor/`; source
checkouts can also load it from the adjacent `components/full-text-search`
directory. The archive should expand to:

```text
language-fts/
  indexer.php
  composer.json
  composer.lock
  LICENSE
  README.md
  docs/
  src/
  vendor/
    wp-php-toolkit/full-text-search/
```

Do not package the whole monorepo as a WordPress plugin. WordPress discovers
plugin headers only at the plugin root and one directory level below
`wp-content/plugins`; a nested monorepo checkout can leave the plugin entrypoint
undiscovered.

## Release Channels

Release artifacts are split by license channel:

- `language-fts-core.zip`: an installable plugin ZIP that carries
  runtime PHP, docs, package-level license evidence, and production Composer
  dependencies. This profile excludes analyzer-pack runtime data, development
  tools, Playground harnesses, raw upstream source submodules, fixtures, local
  build artifacts, and credentials.
- `language-fts-full.zip`: an installable GitHub/full plugin ZIP. This profile
  may include CC BY-SA UniMorph packs with their notices, provenance, source
  locks, manifests, and runtime shards. Unknown-license packs still remain
  blocked.
- `language-fts-extended-language-packs.zip`: a separate optional language-pack
  bundle for approved analyzer packs. It is not an installable plugin ZIP and
  is not bundled with the core package. It includes a
  top-level `manifest.json`, `NOTICE.txt`, `LICENSES.md`, each pack manifest,
  each pack notice, `PROVENANCE.md`, `SOURCE.lock.json`, and runtime shards.
- `language-fts-extended-language-packs.manifest.json`: a signed release
  manifest that records the optional language-pack bundle name, immutable
  GitHub release asset URL, byte size, and SHA-256 hash.
- `language-fts-extended-language-packs.manifest.json.sig`: the detached
  Ed25519 signature for the release manifest. The plugin verifies this
  signature with its embedded public key before downloading or installing the
  optional bundle.
- `language-fts-release-evidence.json`: machine-readable release evidence for
  the reviewed checkout and selected release target.

The analyzer-pack release policy is intentionally fail-closed. Packs with
`upstream-license-not-declared`, missing SPDX identifiers, fixture-only
metadata, or source-only/raw-submodule status are excluded from every
distributable artifact. The policy is checked by packaging tests and by the
builders before ZIP creation.

| Analyzer pack class | Core ZIP | GitHub full ZIP | Extended pack bundle |
| --- | --- | --- | --- |
| Plugin PHP, docs, package license, production Composer dependencies | Included | Included | Not included |
| Development tools and Playground harnesses | Excluded | Excluded | Not included |
| BSD-2-Clause Polish PoliMorf runtime pack | Excluded | Included | Included with notices and provenance |
| CC BY-SA UniMorph runtime packs | Excluded | Included with notices and provenance | Included with notices and provenance |
| `upstream-license-not-declared` packs | Excluded | Excluded | Excluded |
| Fixture-only packs | Excluded | Excluded | Excluded |
| Raw upstream sources under `resources/sources/` | Excluded | Excluded | Excluded |

The core package is an installable release ZIP, not a WordPress.org submission,
approval, endorsement, hosted asset, SVN commit, tag, GitHub release, or upload.

## GitHub Release Workflow

Language FTS release assets are built by the manual/tag workflow in
`.github/workflows/release-language-fts.yml`. The workflow validates the
release packaging contracts, builds every release-channel asset, inspects the
ZIP contents for license-policy violations, signs the optional language-pack
release manifest, writes release notes, writes `SHA256SUMS.txt`, and then
creates or updates a GitHub release with the generated files.

Recommended release tags use the `language-fts-v*` prefix:

- `language-fts-v0.1.10` for a normal release;
- `language-fts-v0.1.10-rc1` for a prerelease;
- `language-fts-v0.1.9-test` for a disposable release test.

Characters after `language-fts-v` must be letters, numbers, dots,
underscores, or hyphens so the plugin can verify the signed language-pack
manifest against a trusted immutable release asset URL.

To run the workflow from GitHub:

1. Open GitHub Actions.
2. Select **Release Language FTS**.
3. Choose **Run workflow**.
4. Enter the release `tag`.
5. Leave `target_ref` blank to release the selected workflow branch/ref, or set
   it to a branch name, tag, or commit SHA.
6. Leave `draft` disabled for a public release. Enable it only when
   intentionally preparing a private review build.
7. Enable `prerelease` only for release candidates or test releases.

The workflow also runs on pushed tags that match `language-fts-v*`. Tag-push
runs use the pushed tag name, build from that tag, and default to a non-draft
release. The workflow does not move existing tags, does not force-push tags,
and does not maintain a moving `latest` tag.

The workflow publishes these release assets:

- `language-fts-core.zip`;
- `language-fts-full.zip`;
- `language-fts-extended-language-packs.zip`;
- `language-fts-extended-language-packs.manifest.json`;
- `language-fts-extended-language-packs.manifest.json.sig`;
- `language-fts-release-evidence.json`;
- `SHA256SUMS.txt`.

Use `language-fts-core.zip` for the smallest direct-install plugin package,
without bundled analyzer-pack data. Use `language-fts-full.zip` only when the
separately licensed CC BY-SA UniMorph packs are acceptable. Use
`language-fts-extended-language-packs.zip` only when you want optional extra
packs outside the core plugin package, and review the bundle notices before
use. In-plugin downloads read the signed
`language-fts-extended-language-packs.manifest.json`, verify
`language-fts-extended-language-packs.manifest.json.sig`, then verify the ZIP
byte size and SHA-256 hash before extraction.

The release workflow requires the repository secret
`LANGUAGE_FTS_LANGUAGE_PACK_MANIFEST_SIGNING_KEY`. The value is a base64-encoded
Ed25519 secret key. Rotate it by generating a new keypair, updating the GitHub
secret with the private key, and updating
`EXTENDED_LANGUAGE_PACKS_MANIFEST_PUBLIC_KEY_BASE64` in `indexer/src/Plugin.php`
with the matching public key before publishing the next release.

An empty manual draft named `language-fts-v0.1.9` may exist at
`https://github.com/adamziel/wp-extensions/releases/tag/untagged-ddb06656129684895c65`.
Do not publish it accidentally. If it is still present when a real Language FTS
release is prepared, either delete and recreate it through the GitHub UI with a
real `language-fts-v*` tag, or run this workflow with the intended tag so it can
create or update the release and upload the generated assets.

This workflow does not submit to WordPress.org, does not commit to SVN, and
does not make the core ZIP approved by WordPress.org.

## Files That Ship

Ship:

- `indexer.php`;
- `src/*.php`;
- `composer.json`;
- `composer.lock`;
- `README.md`;
- `docs/*.md`;
- `LICENSE`;
- runtime Composer dependencies under `vendor/`, including
  `wp-php-toolkit/full-text-search`, for release archives.

Do not ship:

- `.git`, `.gitignore`, or `.distignore`;
- nested dependency dotfiles such as `.gitattributes`, `.gitignore`, and
  `.distignore`;
- `.cao/` task and review artifacts;
- `review-artifacts/`;
- `tests/`;
- `tools/`;
- `playground/`;
- `goal.md`;
- Composer auth files such as `language-fts/auth.json` and
  `language-fts/.composer/auth.json`;
- `resources/sources/` raw upstream source submodules such as Jieba and
  UniMorph checkouts;
- profile-optional `resources/analyzer-packs/` runtime manifests, notices,
  provenance, source locks, and runtime shards;
- `vendor/bin`;
- dependency-internal test and coverage fixtures under `vendor/`, including
  `vendor/wp-php-toolkit/full-text-search/tests/`;
- local caches, logs, and temporary files.

The `.distignore` file in this directory encodes that packaging boundary.

The package includes WordPress.org-style readme metadata, GPL-compatible package
license evidence, and directory asset images. WordPress.org or SVN submission
still requires a recorded public-submission authority review in addition to
these package checks.

## Release Readiness Gate

Run the release-readiness gate before publishing any package. The gate has two
targets because direct-install readiness is not the same as WordPress.org/SVN
or broader public-marketplace submission readiness.

Direct-install readiness proves the current ZIP release path:

```sh
php indexer/tools/check-release-readiness.php --target=direct-install
```

This target checks the plugin header version, Composer metadata, direct ZIP
builder, `language-fts/` package root, required runtime files, production Composer
dependencies, prohibited release artifacts, and ZIP boundary. The default
readiness path uses a stable temporary build directory and normalized ZIP entry
metadata so two unchanged runs produce identical JSON, including the operator
evidence for ZIP path and SHA-256. Runs that share that default build directory
are serialized with a local advisory lock while staging, ZIP creation, and
post-build validation are in progress, so overlapping readiness checks cannot
observe a partially restaged package. A passing direct-install check means the
project can produce the supported direct ZIP; it does not approve public
marketplace distribution.

Public-submission readiness is intentionally separate:

```sh
php indexer/tools/check-release-readiness.php --target=public-submission
```

This target remains blocked until
`docs/public-submission-readiness.json` records a completed authority review.
The checker still validates package-level `readme.txt`, the package-level
GPL-compatible license file, public redistribution license policy, and
WordPress.org-style banner/icon assets so regressions in those package artifacts
block submission readiness again.

The public-submission asset check requires the exact PNG files
`assets/banner-772x250.png` and `assets/icon-128x128.png`. The banner must be
exactly 772 by 250 pixels and the icon must be exactly 128 by 128 pixels. Files
with those names are rejected when they are malformed, not PNGs, 1x1 or
wrong-size placeholders, or blank single-color images.

The public-submission authority evidence file is intentionally not a placeholder
marker. To pass, it must record an approved WordPress.org/public-submission
target, non-placeholder approver, review date, and explicit approved checks for
readme, license, assets, and public-submission authority.

Use this shape only after an authorized reviewer has completed that review:

```json
{
  "status": "approved",
  "target": "wordpress.org-plugin-directory",
  "approver": "Reviewer name",
  "reviewed_at": "YYYY-MM-DD",
  "checks": {
    "readme": true,
    "license": true,
    "assets": true,
    "public_submission_authority": true
  }
}
```

Do not commit `docs/public-submission-readiness.json` with placeholder values.

## Release Evidence Bundle

The release evidence collector gives release reviewers one sanitized JSON bundle
for the current checkout:

```sh
php indexer/tools/collect-release-evidence.php
The collector has an explicit release target:

```sh
php indexer/tools/collect-release-evidence.php --release-target=direct-install
php indexer/tools/collect-release-evidence.php --release-target=public-submission
```

The default target is `direct-install`, matching the supported installable ZIP
release workflow. The default bundle is safe to run before release assets exist:
it does not build or write a direct-install ZIP by default. Because
direct-install readiness is the required lane for the default target, the
default bundle is `blocked` until the operator either explicitly allows
direct-install readiness to stage/build artifacts or supplies an already staged
package directory. Public-submission readiness is still included as non-target
evidence, and it must continue to say that public submission is not approved and
remains blocked if that target is selected.

Use a direct-install target bundle with explicit readiness evidence when a
review needs a truthful pass/fail bundle for the supported release path:

```sh
php indexer/tools/collect-release-evidence.php \
  --release-target=direct-install \
  --run-direct-install-readiness
php indexer/tools/collect-release-evidence.php \
  --release-target=direct-install \
  --direct-package-dir=/path/to/staged/language-fts
php indexer/tools/collect-release-evidence.php \
  --release-target=direct-install \
  --run-docker-disposable-smokes
php indexer/tools/collect-release-evidence.php \
  --release-target=direct-install \
  --run-docker-lifecycle-smokes
php indexer/tools/collect-release-evidence.php \
  --release-target=direct-install \
  --run-docker-upgrade-multisite-smoke \
  --previous-direct-package=/path/to/previous-wp-fts-indexer.zip
php indexer/tools/collect-release-evidence.php \
  --release-target=direct-install \
  --run-docker-upgrade-multisite-smoke \
  --previous-direct-package-ref=PREVIOUS_LOCAL_REF_OR_SHA
```

Use the public-submission target only for WordPress.org/SVN or other public
marketplace submission review:

```sh
php indexer/tools/collect-release-evidence.php --release-target=public-submission
```

That target remains `blocked` on current main until authority evidence is
supplied. The bundle also records
skip/pass/fail evidence for the host-configured disposable WordPress release
smoke, host-configured provider compatibility smoke, explicitly opted-in Docker
disposable release/provider smoke, explicitly opted-in Docker disposable
lifecycle smoke, real WordPress/MySQL integration proof, real MySQL production
proof, and PR-safe production-scale benchmark. The benchmark lane is generated
pure-PHP evidence and includes bounded structural gates plus conservative
index/search performance-budget gates for the deterministic generated corpus;
it fails when benchmark JSON reports failed gates. It is not live MySQL proof,
production-traffic proof, or public-submission certification. The
release/provider Docker lane builds a temporary direct-install ZIP and
disposable WordPress/MariaDB stack so it can replace the host-environment skip
with direct-install release/provider smoke evidence when Docker is available.
The lifecycle Docker lane is direct-install/operator lifecycle evidence: it
installs a source copy in a disposable WordPress/MariaDB stack, proves
activation/repair/deactivation and uninstall retention boundaries, and does not build a public-submission artifact.
Multisite lifecycle proof is explicitly not run by that lane, and the collector
records that boundary.

The upgrade/multisite Docker lane is direct-install/operator upgrade evidence:
it requires either `--previous-direct-package=/path/to/previous-wp-fts-indexer.zip`
or `--previous-direct-package-ref=PREVIOUS_LOCAL_REF_OR_SHA`. The ref form is
for release reviews that need repo-owned evidence without a manually supplied
ZIP: the collector resolves the local ref/SHA without fetching, rejects the
current target commit, verifies release-build tooling and the committed
Composer lockfile at that ref, archives only the package source paths into
temporary storage, and builds the previous ZIP with isolated Composer
home/auth, an existing local Composer package cache when available, network
access disabled, and credential-capable environment variables scrubbed before
the historical builder or nested Composer process can inherit them. Historical
refs containing Composer auth files such as `indexer/auth.json` or
`indexer/.composer/auth.json` are rejected before checkout/archive. The lane then
builds the current ZIP in temporary storage, installs WordPress as a disposable
multisite network, network-activates the previous package, upgrades to the
current package, checks schema version/status after upgrade, repair idempotence
after upgrade, search continuity for generated fixture content, queue health
after upgrade, creates an additional disposable site, proves that site's
per-prefix `fts_*` tables, proves subsite indexing/search/queue/repair behavior,
proves the WordPress site-deletion table filter contributes the target site's
FTS tables, and cleans up generated fixtures and temporary resources.
Missing or invalid previous packages/refs are `unavailable`, not passes. The lane only
passes when the decoded wrapper proof records `multisite_evidence_status` as `passed`.
These lanes do not modify WordPress.org/SVN state, tags, public assets, package
readme/license files, or authority evidence, and a direct-install `pass` does
not approve public-submission readiness.

## Composer Dependency Handling

The source tree tracks `composer.json` and `composer.lock`, and ignores
`vendor/`. WordPress does not run Composer for installed plugins, so a release
archive should install production dependencies from the committed lockfile
before the ZIP is built.

Current runtime dependencies:

- `wp-php-toolkit/full-text-search`, the framework-neutral FTS component used
  by the plugin adapter;
- `wamania/php-stemmer`, used only when stemming is enabled and the language is
  one of the optional Wamania-backed allowlist entries: Catalan (`ca`) or Dutch
  Porter (`nl`).

The plugin bootstrap prefers the adjacent `../components/full-text-search`
source when it exists in a monorepo checkout, then loads `vendor/autoload.php`
when Composer vendor files are present. A standalone plugin ZIP must include
vendor files because the adjacent monorepo component will not exist inside
`wp-content/plugins/language-fts`.

## Build Release Artifacts

Run from the monorepo checkout:

```sh
BUILD="$(mktemp -d)"
BUILD_FULL="$(mktemp -d)"
BUILD_PACKS="$(mktemp -d)"
php indexer/tools/build-release-zip.php \
  --profile=core \
  --build-dir="$BUILD" \
  --output="$BUILD/language-fts-core.zip"
php indexer/tools/build-release-zip.php \
  --profile=github-full \
  --build-dir="$BUILD_FULL" \
  --output="$BUILD_FULL/language-fts-full.zip"
php indexer/tools/build-language-pack-bundle.php \
  --profile=extended-language-packs \
  --build-dir="$BUILD_PACKS" \
  --output="$BUILD_PACKS/language-fts-extended-language-packs.zip"
php indexer/tools/build-language-pack-release-manifest.php \
  --zip="$BUILD_PACKS/language-fts-extended-language-packs.zip" \
  --asset-url="https://github.com/adamziel/wp-extensions/releases/download/language-fts-v0.1.10/language-fts-extended-language-packs.zip" \
  --version="language-fts-v0.1.10" \
  --output="$BUILD_PACKS/language-fts-extended-language-packs.manifest.json" \
  --signature-output="$BUILD_PACKS/language-fts-extended-language-packs.manifest.json.sig"
```

The builder stages source `indexer/` as package root `language-fts/` through
`.distignore`, copies the local `components/full-text-search` package for
Composer's path repository, runs `composer install --no-dev --optimize-autoloader`
with a scrubbed Composer environment, removes vendor development directories such as `vendor/bin`,
`test`, `tests`, `Tests`, and `coverage`. The builder prunes staged dotfiles anywhere in the package before ZIP creation,
applies the selected analyzer-pack release profile,
and refuses staged Composer auth files such as `language-fts/auth.json` or
`language-fts/.composer/auth.json` before dependency installation so Composer cannot
read source-tree credentials. This removes nested Composer dependency files such as
`language-fts/vendor/wamania/php-stemmer/.gitignore` before they can enter the
archive. If multiple builds use the same `--build-dir`, they are serialized with
the same advisory lock used by the readiness gate.

Inspect the archive contents:

```sh
php -r '$z=new ZipArchive(); $z->open($argv[1]); for ($i=0; $i<$z->numFiles; $i++) { echo $z->getNameIndex($i), PHP_EOL; }' "$BUILD/language-fts-core.zip" | sed -n '1,120p'
```

The core listing should include production `language-fts/vendor/` dependencies
and `language-fts/RELEASE-CHANNEL.txt`. It should not include analyzer-pack
runtime directories, development `language-fts/tools/`, Playground harnesses,
unknown-license pack directories, `.cao`, root `language-fts/tests/`,
dependency-internal vendor tests such as
`language-fts/vendor/wp-php-toolkit/full-text-search/tests/*`,
`language-fts/vendor/bin/`, dependency dotfiles such as
`language-fts/vendor/wamania/php-stemmer/.gitignore`, Composer auth files such
as `language-fts/auth.json` or `language-fts/.composer/auth.json`,
`review-artifacts`, `resources/sources`, `tools/`, or `playground/`. The builder fails before ZIP
creation if the staged package still contains Composer auth files, prohibited dotfiles, root tests,
review artifacts, raw source checkouts, vendor binaries, or vendor test/coverage
fixtures.

Install the archive into a disposable WordPress site:

```sh
wp plugin install "$BUILD/language-fts-core.zip" --activate
wp fts search "__schema_probe__" --limit=1
```

The schema probe should succeed even before any content is indexed.

## Release Checklist

1. Start from a clean worktree.
2. Run the normal PHP harness and any required hardening acceptance commands.
3. Run `php indexer/tools/check-release-readiness.php --target=direct-install`.
4. Run `php indexer/tools/collect-release-evidence.php --release-target=direct-install --run-direct-install-readiness`
   or use `--direct-package-dir=/path/to/staged/language-fts` when validating an
   existing staged package.
5. Run `php indexer/tools/check-release-readiness.php --target=public-submission`
   and treat the current blockers as expected unless the release explicitly
   includes a completed public-submission authority pass.
6. Build the core ZIP, GitHub full ZIP, and extended language pack bundle in
   temporary directories.
7. Inspect the ZIPs for the release-channel license matrix: analyzer packs
   absent from core, approved packs present in full/extended, and
   unknown-license packs absent everywhere.
8. Inspect the ZIPs for unexpected `.cao`, dotfiles, root `tests/`,
   dependency-internal vendor tests or coverage fixtures, or local cache files.
9. Install the core or full plugin ZIP in a disposable WordPress site.
10. Activate the plugin, run the schema probe, run a small reindex, and run one
   search.
11. Record the commit SHA, archive names, dependency versions, readiness target
   results, and test results in
   the release notes.
