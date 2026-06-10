# Release Packaging

Language FTS Playground ships as a plain WordPress plugin directory. It has no
Composer or Node runtime dependency, so the release package is a filtered copy
of the plugin source with the bundled lexical resources preserved.

## Build

Run the maintained builder from the repository root:

```sh
php language-fts-playground/tools/build-release.php
```

The default artifact path is:

```text
language-fts-playground/dist/language-fts-playground-0.3.0.zip
```

Pass `--output=/absolute/path` to write the zip elsewhere. The default `dist/`
path is ignored by git. A normal release build requires the
`language-fts-playground/` source tree to be clean. Use `--allow-dirty` only for
local smoke builds from an uncommitted checkout.

The builder verifies that the plugin header version and
`LANGUAGE_FTS_PLAYGROUND_VERSION` agree, runs PHP lint across the plugin
checkout, runs `php tests/run.php`, verifies the WordPress.org `readme.txt`
metadata, decodes `playground/blueprint.json`, runs `git diff --check`, stages
tracked plugin files into a single
`language-fts-playground/` directory, writes a versioned zip with normalized
entry mtimes, and runs the package verifier.

To inspect a previously built zip without rebuilding:

```sh
php language-fts-playground/tools/verify-release-zip.php \
  --zip=language-fts-playground/dist/language-fts-playground-0.3.0.zip
```

To rerun the release packaging regressions that protect deterministic output and
ignored local artifact exclusion:

```sh
php language-fts-playground/tools/test-release-packaging.php
```

## Zip Contents

The installable zip contains one top-level `language-fts-playground/`
directory with:

- `language-fts-playground.php`
- `LICENSE`
- `readme.txt`
- `src/`
- `resources/languages/`
- `README.md`
- `docs/`
- `playground/blueprint.json`

The bundled `resources/languages/` tree is required at runtime and must stay in
the package. The Playground Blueprint is included so the same release payload
keeps its public demo configuration visible; the stable Blueprint still installs
from the `main` branch by design, so package smoke tests should install the zip
directly in a disposable site.

The zip excludes:

- `.git/`, `.github/`, `.cao/`, `.tmp/`, and local editor files
- `node_modules/`
- `dist/`, `static-site-output/`, generated zips, logs, review artifacts, and
  smoke artifacts
- `tests/` and all test fixtures
- `tools/`

Tests and tools are source-checkout maintenance assets. They are not needed for
plugin activation or the admin demo, so they stay out of the user artifact to
keep the package smaller and avoid shipping review fixtures.

The packaged `readme.txt` is WordPress.org-compatible metadata for a future
plugin-directory submission path. It must keep the current 0.3.0 artifact
scoped as a demo/seed-pack direct-ZIP release candidate until a separate
WordPress.org submission, directory asset, and policy-review workflow is
completed.

## WordPress.org SVN Dry Run

The WordPress.org SVN staging helper is separate from the direct-ZIP builder.
It creates a dry-run tree with plugin files directly under `trunk/`, a matching
`tags/<version>/` copy, and a top-level `assets/` directory:

```sh
php language-fts-playground/tools/build-wordpress-org-svn-stage.php
```

To inspect an existing stage:

```sh
php language-fts-playground/tools/verify-wordpress-org-svn-stage.php \
  --stage=language-fts-playground/dist/wordpress-org-svn-stage
```

The dry-run stage does not upload to WordPress.org, request a slug, create
directory assets, or prove submission readiness. `--submission-readiness` on
the SVN stage verifier is a local asset inventory gate for a future approved
asset set; it still does not contact WordPress.org services. See
`docs/wordpress-org-svn-staging.md` and
`docs/wordpress-org-directory-assets.md` for the full runbooks and remaining
gates.

## Release Checklist

1. Update the plugin header version and `LANGUAGE_FTS_PLAYGROUND_VERSION`.
2. Update user-facing release notes or README details as needed.
3. Run `php language-fts-playground/tools/verify-wordpress-org-readme.php`.
4. Run `php language-fts-playground/tools/build-release.php`.
5. Run `php language-fts-playground/tools/test-release-packaging.php`.
6. Inspect the zip listing and confirm one top-level plugin directory.
7. Install the zip in a disposable WordPress or Playground site, activate
   `Language FTS Playground`, and open `Tools -> Language FTS`.
8. Do not commit generated zips unless release policy changes.
