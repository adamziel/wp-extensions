# WordPress.org SVN Staging

Language FTS Playground is still distributed as a direct ZIP release candidate.
This workflow builds a dry-run WordPress.org Plugin Directory SVN layout so the
SVN blocker can be rehearsed separately from the direct-ZIP package.

The stage is not an SVN checkout and the builder never uploads to
WordPress.org. Do not claim WordPress.org availability until the official
submission, review, SVN credentials, directory assets, policy review, and final
release rehearsal have all passed.

## Build A Dry-Run Stage

Run from the repository root:

```sh
php language-fts-playground/tools/build-wordpress-org-svn-stage.php \
  --output=language-fts-playground/dist/wordpress-org-svn-stage
```

The builder requires a clean `language-fts-playground/` source tree by default.
It verifies that the plugin header `Version`,
`LANGUAGE_FTS_PLAYGROUND_VERSION`, and readme `Stable tag` agree, runs the
plugin PHP lint, test suite, WordPress.org readme verifier, Blueprint JSON
decode check, and `git diff --check`, then stages tracked plugin files while
honoring `.distignore`.

For local iteration from an uncommitted checkout, pass `--allow-dirty`. For a
fast layout-only build, pass `--skip-checks`; do not use skipped checks for a
release rehearsal result.

The generated layout is:

```text
wordpress-org-svn-stage/
  assets/
  tags/
    0.3.0/
      language-fts-playground.php
      LICENSE
      README.md
      readme.txt
      docs/
      playground/blueprint.json
      resources/languages/
      src/
  trunk/
    language-fts-playground.php
    LICENSE
    README.md
    readme.txt
    docs/
    playground/blueprint.json
    resources/languages/
    src/
```

The plugin files live directly under `trunk/` and `tags/<version>/`. There must
not be nested `trunk/language-fts-playground/` or
`tags/<version>/language-fts-playground/` directories.

## Rebuild An Existing Generated Stage

The builder writes a small marker file at the stage root. To replace a previous
generated stage:

```sh
php language-fts-playground/tools/build-wordpress-org-svn-stage.php \
  --output=language-fts-playground/dist/wordpress-org-svn-stage \
  --replace
```

The marker protects unrelated directories from accidental replacement. The
marker is a dry-run artifact and must not be committed to a real WordPress.org
SVN repository.

## Verify A Stage

To inspect a generated stage:

```sh
php language-fts-playground/tools/verify-wordpress-org-svn-stage.php \
  --stage=language-fts-playground/dist/wordpress-org-svn-stage
```

The verifier checks:

- top-level `assets/`, `tags/`, and `trunk/` directories;
- no nested plugin root under `trunk/` or `tags/<version>/`;
- one `tags/<version>/` directory matching the plugin header version;
- plugin header version, version constant, and readme `Stable tag` alignment;
- required runtime files, docs, bundled language resources, and Blueprint;
- no `.git`, `.github`, `.cao`, `dist`, `tests`, `tools`, generated zips, logs,
  smoke artifacts, or review artifacts;
- no `assets/` directory inside `trunk/` or `tags/<version>/`;
- supported top-level asset filenames, matching image formats, fixed dimensions
  for banners/icons, and optional submission-readiness asset coverage.

For a machine-readable review artifact:

```sh
php language-fts-playground/tools/verify-wordpress-org-svn-stage.php \
  --stage=language-fts-playground/dist/wordpress-org-svn-stage \
  --manifest-json=/tmp/language-fts-wordpress-org-svn-stage-manifest.json
```

The manifest path must be outside the stage root.

## Directory Assets

If approved GPL-compatible directory assets exist, pass their source directory:

```sh
php language-fts-playground/tools/build-wordpress-org-svn-stage.php \
  --output=language-fts-playground/dist/wordpress-org-svn-stage \
  --assets-source=/absolute/path/to/approved-assets \
  --replace
```

The initial allowlist is intentionally narrow:

- `banner-772x250.png`
- `banner-1544x500.png`
- `icon-128x128.png`
- `icon-256x256.png`
- `screenshot-<n>.png`
- `screenshot-<n>.jpg`

Screenshots must have matching numbered captions in `readme.txt`. If screenshot
assets are added, remove the placeholder screenshots wording before validating
the stage.

`--strict-assets` on the verifier requires at least the launch banner,
128-by-128 icon, and one screenshot. `--submission-readiness` additionally
requires the high-resolution banner, 256-by-256 icon, contiguous screenshot
numbering starting at `screenshot-1`, matching image formats, and matching
readme captions. Both modes are local checks only; neither uploads to SVN or
contacts WordPress.org services.

See `docs/wordpress-org-directory-assets.md` for the asset inventory, source
and license runbook, and the remaining external submission gates.

## Regression Checks

Run the focused staging regressions:

```sh
php language-fts-playground/tools/test-wordpress-org-svn-stage.php
```

The regression script builds a temporary stage, writes a manifest, and verifies
that the verifier rejects nested plugin roots under both `trunk/` and
`tags/<version>/`, payload-level `assets/`, a forbidden `tools/` directory,
unsupported asset filenames, missing or misplaced submission-readiness assets,
wrong banner/icon dimensions, and image formats that do not match filenames.
It also verifies a complete generated local fixture with `--submission-readiness`.

## Release Rehearsal Notes

Before a real Plugin Directory release push, record:

1. branch and commit SHA used to build the stage;
2. local readme verifier result;
3. official WordPress.org readme validator result for the exact `readme.txt`;
4. stage verifier result and manifest path;
5. directory asset license/source inventory;
6. focused Plugin Directory policy/security review result;
7. `svn status` output from the real SVN checkout;
8. planned `svn copy trunk tags/<version>` operation;
9. support forum notification owner and first-push operator.

This dry-run workflow clears the local SVN layout rehearsal gap only. It does
not clear the official readme validation, account/slug, asset approval,
WordPress.org review, or final public support-boundary gates.
