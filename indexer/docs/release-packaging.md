# Release Packaging

Release archives should package the `indexer` directory as the WordPress plugin
root. The reusable FTS engine ships through Composer under `vendor/`; source
checkouts can also load it from the adjacent `components/full-text-search`
directory. The archive should expand to:

```text
indexer/
  indexer.php
  composer.json
  composer.lock
  README.md
  docs/
  playground/
    blueprint.json
    sqlite-smoke-blueprint.json
    sqlite-smoke.php
  resources/
    analyzer-packs/
  src/
  tools/
  vendor/
    wp-php-toolkit/full-text-search/
```

Do not package the whole monorepo as a WordPress plugin. WordPress discovers
plugin headers only at the plugin root and one directory level below
`wp-content/plugins`; a nested monorepo checkout can leave `indexer/indexer.php`
undiscovered.

## Files That Ship

Ship:

- `indexer.php`;
- `src/*.php`;
- `composer.json`;
- `composer.lock`;
- `README.md`;
- `docs/*.md`;
- `playground/*.json` and `playground/sqlite-smoke.php`;
- `resources/analyzer-packs/` runtime manifests, notices, provenance, and
  runtime shards that the plugin can validate locally;
- `tools/` importer, validator, audit, and external-pack helper scripts;
- runtime Composer dependencies under `vendor/`, including
  `wp-php-toolkit/full-text-search`, for release archives.

Do not ship:

- `.git`, `.gitignore`, or `.distignore`;
- nested dependency dotfiles such as `.gitattributes`, `.gitignore`, and
  `.distignore`;
- `.cao/` task and review artifacts;
- `review-artifacts/`;
- `tests/`;
- `goal.md`;
- `resources/sources/` raw upstream source submodules such as Jieba and
  UniMorph checkouts;
- generated preview/archive files such as `playground/indexer-preview.zip`;
- `vendor/bin`;
- dependency-internal test and coverage fixtures under `vendor/`, including
  `vendor/wp-php-toolkit/full-text-search/tests/`;
- local caches, logs, and temporary files.

The `.distignore` file in this directory encodes that packaging boundary.

This package is a direct-install ZIP boundary only. It does not make the plugin
ready for WordPress.org or SVN submission, which still needs complete
WordPress.org-style readme metadata, GPL-compatible license files and metadata,
valid directory asset images, and recorded public-submission authority evidence.

## Release Readiness Gate

Run the release-readiness gate before publishing any package. The gate has two
targets because direct-install readiness is not the same as WordPress.org/SVN
or broader public-marketplace submission readiness.

Direct-install readiness proves the current ZIP release path:

```sh
php indexer/tools/check-release-readiness.php --target=direct-install
```

This target checks the plugin header version, Composer metadata, direct ZIP
builder, `indexer/` package root, required runtime files, production Composer
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

Current main is expected to fail this target. The package does not yet carry a
complete package-level `readme.txt`, package-level GPL-compatible license file,
public redistribution license policy, valid WordPress.org-style banner/icon
assets, or `docs/public-submission-readiness.json` authority evidence. The
checker must continue to report those blockers until the project intentionally
supplies and verifies the WordPress.org-style metadata/assets/license evidence
needed for public submission.

The public-submission authority evidence file is intentionally not a placeholder
marker. To pass, it must record an approved WordPress.org/public-submission
target, non-placeholder approver, review date, and explicit approved checks for
readme, license, assets, and public-submission authority.

## Release Evidence Bundle

The release evidence collector gives release reviewers one sanitized JSON bundle
for the current checkout:

```sh
php indexer/tools/collect-release-evidence.php
```

The default bundle is safe to run before release assets exist. It does not build
or write a direct-install ZIP by default; that lane is reported as `skip` with
an explicit artifact policy. It does run the public-submission readiness target
and records the current blockers as `blocked` evidence rather than treating them
as a collector failure. It also records skip/pass/fail evidence for the
disposable WordPress release smoke, provider compatibility smoke, real
WordPress/MySQL integration proof, real MySQL production proof, and PR-safe
production-scale benchmark.

Use direct-install opt-ins only when a review intentionally wants artifact
staging evidence:

```sh
php indexer/tools/collect-release-evidence.php --run-direct-install-readiness
php indexer/tools/collect-release-evidence.php --direct-package-dir=/path/to/staged/indexer
```

The bundle does not modify WordPress.org/SVN state, tags, or release assets, and
it is not public-submission approval. The current public-submission blockers are
expected until product policy, package assets, license/readme metadata, and
authority evidence are completed.

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
`wp-content/plugins/indexer`.

## Build A ZIP

Run from the monorepo checkout:

```sh
BUILD="$(mktemp -d)"
php indexer/tools/build-release-zip.php \
  --build-dir="$BUILD" \
  --output="$BUILD/wp-fts-indexer.zip"
```

The builder stages `indexer/` through `.distignore`, copies the local
`components/full-text-search` package for Composer's path repository, runs
`composer install --no-dev --optimize-autoloader`, removes vendor development
directories such as `vendor/bin`, `test`, `tests`, `Tests`, and `coverage`, then
prunes staged dotfiles anywhere in the package before ZIP creation. This removes
nested Composer dependency files such as
`indexer/vendor/wamania/php-stemmer/.gitignore` before they can enter the
archive. If multiple builds use the same `--build-dir`, they are serialized with
the same advisory lock used by the readiness gate.

Inspect the archive contents:

```sh
php -r '$z=new ZipArchive(); $z->open($argv[1]); for ($i=0; $i<$z->numFiles; $i++) { echo $z->getNameIndex($i), PHP_EOL; }' "$BUILD/wp-fts-indexer.zip" | sed -n '1,120p'
```

The listing should include `indexer/resources/analyzer-packs/`,
`indexer/tools/`, and production `indexer/vendor/` dependencies. It should not
include `.cao`, root `indexer/tests/`, dependency-internal vendor tests such as
`indexer/vendor/wp-php-toolkit/full-text-search/tests/*`, `indexer/vendor/bin/`,
dependency dotfiles such as `indexer/vendor/wamania/php-stemmer/.gitignore`,
`review-artifacts`, `resources/sources`, or the nested
`playground/indexer-preview.zip` preview archive. The builder fails before ZIP
creation if the staged package still contains prohibited dotfiles, root tests,
review artifacts, raw source checkouts, vendor binaries, or vendor test/coverage
fixtures.

Install the archive into a disposable WordPress site:

```sh
wp plugin install "$BUILD/wp-fts-indexer.zip" --activate
wp fts search "__schema_probe__" --limit=1
```

The schema probe should succeed even before any content is indexed.

## Release Checklist

1. Start from a clean worktree.
2. Run the normal PHP harness and any required hardening acceptance commands.
3. Run `php indexer/tools/check-release-readiness.php --target=direct-install`.
4. Run `php indexer/tools/check-release-readiness.php --target=public-submission`
   and treat the current blockers as expected unless the release explicitly
   includes a completed public-submission authority pass.
5. Build the release ZIP with `php indexer/tools/build-release-zip.php`.
6. Inspect the ZIP for unexpected `.cao`, dotfiles, root `tests/`,
   dependency-internal vendor tests or coverage fixtures, or local cache files.
7. Install the ZIP in a disposable WordPress site.
8. Activate the plugin, run the schema probe, run a small reindex, and run one
   search.
9. Record the commit SHA, archive name, dependency versions, readiness target
   results, and test results in
   the release notes.
