# Release Packaging

This repository is a Composer-based WordPress plugin source tree. A production
zip must include runtime dependencies and exclude loop logs, tests, temporary
files, and local development state.

## Current Release State

The plugin header currently declares version `0.1.0`, requires WordPress `6.0`
or newer, and requires PHP `7.2.24` or newer. The Composer package requires
`wp-php-toolkit/data-liberation`.

Before a release, decide whether the release target is:

- A private/operator zip for direct installation.
- A WordPress.org SVN release.

Both targets need a clean working tree and passing checks.

## Preflight

Run these checks from the repository root:

```bash
composer install
composer test
composer lint
composer validate --strict
git diff --check
```

For a production zip, use the maintained build script:

```bash
composer build:release
```

The script requires a clean git working tree, verifies the plugin header
version, `UNIVERSAL_IMPORTER_VERSION`, and `readme.txt` stable tag agree, runs
the standard checks, stages a single `universal-wordpress-importer/` directory,
installs production Composer dependencies inside that staging directory, honors
`.distignore`, writes a versioned zip under `dist/`, and verifies package
integrity before reporting success.

The package-integrity verifier checks that the zip has one plugin root,
contains required runtime files such as `vendor/autoload.php`, excludes
development paths such as `tests/`, `tools/`, `.autonomous-loop/`, and
`vendor/bin/`, and rejects unsafe archive entries such as traversal paths,
duplicates, and symlinks. To inspect a previously built zip without rebuilding
or activating WordPress, run:

```bash
php tools/verify-release-zip.php --zip=dist/universal-wordpress-importer-0.1.0.zip
```

For local packaging smoke tests after checks have already run, use:

```bash
php tools/build-release.php --skip-checks --allow-dirty --use-existing-vendor
```

The `--use-existing-vendor` flag is only for local smoke tests. Production
builds should let the script run `composer install --no-dev` in the staging
directory so dev-only packages are not shipped.

Then activate the packaged plugin in a disposable WordPress site and verify:

```bash
wp plugin activate universal-wordpress-importer
wp universal-importer import ./sample-content --dry-run
wp universal-importer status import_...
wp universal-importer tick import_...
```

The maintained automated smoke path defaults to `--runtime=auto`. It first
tries WordPress Playground, which creates a clean disposable WordPress install,
installs the generated zip, activates the plugin, verifies importer tables, and
runs the plugin's WP-CLI import/tick commands:

```bash
composer smoke:release
```

For a previously built zip, run:

```bash
php tools/smoke-release-activation.php --zip=dist/universal-wordpress-importer-0.1.0.zip
```

The smoke tool creates a temporary Blueprint bundle containing the release zip
as a bundled resource, then runs `@wp-playground/cli` with a `run-blueprint`
activation script. If Playground cannot download WordPress, boot the runtime,
or does not expose the assertion markers to the host process, `auto` falls back
to a local clean-site runtime.

The local fallback uses WP-CLI plus a private temporary MariaDB server. It
downloads WordPress into a temporary directory, installs the packaged zip,
activates it, checks importer tables, creates a Markdown import session, and
runs a continuation tick. To force this path:

```bash
php tools/smoke-release-activation.php --zip=dist/universal-wordpress-importer-0.1.0.zip --runtime=local
```

The fallback needs `mariadb-install-db`, `mariadbd` or `mysqld`, and `mariadb`
or `mysql` on `PATH`. If a global `wp` command is not present, it downloads the
official WP-CLI Phar; pass `--wp-cli-phar=/path/to/wp-cli.phar` to use a
pre-fetched copy. Use `--runtime=playground` when you need strict Playground
coverage without local fallback.

## Zip Contents

The installable zip should contain a single top-level
`universal-wordpress-importer/` directory with:

- `universal-wordpress-importer.php`
- `composer.json`
- `composer.lock`
- `vendor/` with production dependencies
- `src/`
- `README.md`
- `readme.txt`
- `CHANGELOG.md`
- `docs/`

Exclude:

- `.git/`
- `.autonomous-loop/`
- `.codex-loop/`
- `tests/`
- `vendor/bin/`
- `phpunit.xml.dist`
- `phpstan.neon.dist`
- `phpstan-stubs/`
- local logs, coverage output, and temporary files

The repository includes `.distignore` with the maintained exclusion list used
by the build script and by release tooling that supports it.

## WordPress.org Release Notes

WordPress.org expects a `readme.txt` using the plugin readme format. Since
WordPress 5.8, requirement headers such as `Requires PHP` and
`Requires at least` are parsed from the main plugin PHP file, but the readme is
still the directory listing source.

For WordPress.org SVN releases:

- Keep the main plugin file version and `readme.txt` stable tag in sync.
- Put deployable code in `trunk/`.
- Copy release code to `tags/<version>/`.
- Put plugin directory assets such as icons, headers, and screenshots in the
  top-level SVN `assets/` directory, not inside the plugin code directory.
- Push only release-ready code to SVN because commits regenerate plugin zips.

Official references:

- Plugin readmes:
  <https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/>
- SVN releases:
  <https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/>
- Plugin assets:
  <https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/>
- Common plugin directory issues:
  <https://developer.wordpress.org/plugins/wordpress-org/common-issues/>

## Release Checklist

1. Update `UNIVERSAL_IMPORTER_VERSION` and the plugin header version.
2. Update `readme.txt` stable tag and changelog.
3. Update `CHANGELOG.md`.
4. Run the full preflight checks.
5. Build a no-dev zip with production dependencies via `composer build:release`.
6. Smoke-test activation, WP-CLI session creation, and tick in a clean
   WordPress site with `composer smoke:release` or an equivalent full install.
7. For WordPress.org, copy the same release payload to SVN `trunk/` and
   `tags/<version>/`.
8. Create an annotated `known-good/...` git tag only when the release candidate
   represents a verified stable state.
