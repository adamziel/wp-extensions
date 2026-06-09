# Testing

Run these commands from the `indexer` directory unless noted otherwise.

## Normal PHP Harness

The main no-WordPress harness exercises analyzer, storage, search, MySQL fake,
WP-CLI fake, and quality tests.

```sh
php tests/run.php
```

Composer exposes the same default harness:

```sh
composer test
```

The harness discovers `tests/quality/*.php` automatically and enforces the
default minimum check count.

## No-Extension Smoke Test

Run the PHP harness with PHP extensions disabled:

```sh
php -n tests/run.php
```

This verifies the fallback paths used when optional extensions are missing.

## Explicit Check Gate

The integrated quality harness is expected to meet at least 1500 checks:

```sh
WP_FTS_MIN_CHECKS=1500 php tests/run.php
```

Use a higher number only when a lane intentionally raises the target.

## Snowball Compliance

The Snowball harness compares supported stemmers against a local checkout of the
official Snowball data.

```sh
SNOWBALL_DATA_DIR=/home/claude/.cache/snowball-data php tests/snowball-compliance.php
```

Composer also exposes:

```sh
SNOWBALL_DATA_DIR=/home/claude/.cache/snowball-data composer test:snowball
```

The harness reports unsupported Snowball languages as skipped. Skips are
expected for languages that are not advertised by `WP_FTS_SnowballStemmer`.

## Optional BM25 Python Reference

Run the Python reference only when the environment has the optional virtualenv
and native library path used by the hardening contract:

```sh
LD_LIBRARY_PATH=/nix/store/f2q5ld1nipl8w1r2w8m6azhlm2varqgb-zlib-1.3.1/lib:/nix/store/cf1a53iqg6ncnygl698c4v0l8qam5a2q-gcc-14.3.0-lib/lib /home/claude/.cache/indexer-bm25s-venv/bin/python tests/bm25_lucene_reference.py
```

If `bm25s` is not installed, the script exits as an explicit optional skip.

## WordPress Playground SQLite Benchmark Probe

Run the committed live benchmark probe from the repository worktree root:

```sh
mkdir -p /tmp/wp-fts-live-benchmark
npx @wp-playground/cli@latest run-blueprint \
  --blueprint=indexer/playground/live-benchmark-blueprint.json \
  --mount="$(pwd)/indexer:/wordpress/wp-content/plugins/indexer" \
  --mount=/tmp/wp-fts-live-benchmark:/wordpress/wp-content/benchmark-output \
  --blueprint-may-read-adjacent-files \
  --verbosity=quiet
cat /tmp/wp-fts-live-benchmark/live-benchmark.json
```

The probe activates the mounted `indexer` plugin in WordPress Playground,
asserts SQLite runtime evidence, inserts a deterministic 72-post corpus across
three explicit language partitions, indexes through `WP_FTS_Indexer`, searches
through `WP_FTS_Searcher`, and writes one JSON artifact with row counts,
timings, memory deltas, probe totals, and hard gates. It also emits a
`WP_FTS_LIVE_PLAYGROUND_BENCHMARK` log line for runners that capture `runPHP`
output.

The hard gates require 72 indexed docs, 72 language-length rows, at least 576
posting rows, exact common-term totals for one and three language partitions, a
snippet-bearing metadata result, index time within 60 seconds, and peak memory
within 128 MiB in the Playground runtime.

## PHP Syntax Check

Run a syntax pass over source and tests:

```sh
find . -path ./vendor -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
```

## WordPress And MySQL Integration Harness

This branch does not include a real WordPress/MySQL integration harness yet. If
the real-integration-tests lane is merged into the active branch, run the exact
command documented by that lane against a disposable WordPress database, not a
production database.

Until that lane is present, the MySQL and WP-CLI coverage in `tests/run.php` uses
test doubles instead of a live WordPress install.

## Diff And Status Checks

From the repository worktree root, always run:

```sh
git diff --check
git status --short --branch
```
