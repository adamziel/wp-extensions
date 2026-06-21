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
ready for WordPress.org or SVN submission, which still needs a separate
readme/assets/license/public-submission authority pass.

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
archive.

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
3. Build the release ZIP with `php indexer/tools/build-release-zip.php`.
4. Inspect the ZIP for unexpected `.cao`, dotfiles, root `tests/`,
   dependency-internal vendor tests or coverage fixtures, or local cache files.
5. Install the ZIP in a disposable WordPress site.
6. Activate the plugin, run the schema probe, run a small reindex, and run one
   search.
7. Record the commit SHA, archive name, dependency versions, and test results in
   the release notes.
