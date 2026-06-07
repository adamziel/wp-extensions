# Release Packaging

Release archives should package the `indexer` directory as the WordPress plugin
root. The archive should expand to:

```text
indexer/
  indexer.php
  composer.json
  README.md
  docs/
  src/
  vendor/        # present in release archives after composer install
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
- `README.md`;
- `docs/*.md`;
- runtime Composer dependencies under `vendor/` for release archives.

Do not ship:

- `.git`, `.gitignore`, or `.distignore`;
- `.cao/` task and review artifacts;
- `tests/`;
- `goal.md`;
- `composer.lock`, unless a future release policy starts tracking it;
- `vendor/bin`;
- local caches, logs, and temporary files.

The `.distignore` file in this directory encodes that packaging boundary.

## Composer Dependency Handling

The source tree tracks `composer.json` and ignores `vendor/` and
`composer.lock`. WordPress does not run Composer for installed plugins, so a
release archive should install production dependencies before the ZIP is built.

Current runtime dependency:

- `wamania/php-stemmer`, used only when stemming is enabled and the language is
  supported by the allowlist.

The plugin bootstrap loads `vendor/autoload.php` only when it exists. Missing
vendor dependencies do not stop the plugin from loading, but Snowball stemming
will be unavailable and the Snowball harness will report supported languages as
skipped.

## Build A ZIP

Run from the monorepo checkout:

```sh
PLUGIN_SRC=/path/to/wp-extensions/indexer
BUILD="$(mktemp -d)"
mkdir -p "$BUILD/indexer"

rsync -a --delete \
  --exclude-from="$PLUGIN_SRC/.distignore" \
  "$PLUGIN_SRC/" "$BUILD/indexer/"

composer install --no-dev --optimize-autoloader --working-dir="$BUILD/indexer"

( cd "$BUILD" && zip -r wp-fts-indexer.zip indexer -x 'indexer/composer.lock' 'indexer/vendor/bin/*' )
```

Inspect the archive contents:

```sh
unzip -l "$BUILD/wp-fts-indexer.zip" | sed -n '1,120p'
```

Install the archive into a disposable WordPress site:

```sh
wp plugin install "$BUILD/wp-fts-indexer.zip" --activate
wp fts search "__schema_probe__" --limit=1
```

The schema probe should succeed even before any content is indexed.

## Release Checklist

1. Start from a clean worktree.
2. Run the normal PHP harness and any required hardening acceptance commands.
3. Build the release ZIP with production Composer dependencies.
4. Inspect the ZIP for unexpected `.cao`, `tests`, or local cache files.
5. Install the ZIP in a disposable WordPress site.
6. Activate the plugin, run the schema probe, run a small reindex, and run one
   search.
7. Record the commit SHA, archive name, dependency versions, and test results in
   the release notes.
