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

## Native Relevance Gold Benchmark

The main harness includes the committed native relevance fixture automatically.
Run the evaluator directly when you need the per-query metrics table:

```sh
php tests/relevance-benchmark.php --suite=tests/fixtures/relevance/native-core.json
php tests/relevance-benchmark.php --suite=tests/fixtures/relevance/native-core.json --json
php -n tests/relevance-benchmark.php --suite=tests/fixtures/relevance/native-core.json
```

The fixture is a modest regression gate for the current analyzer/searcher
contract. It reports recall@5, precision@5, MRR, nDCG@5, and cross-language
false positives; it is not a production relevance-quality claim.

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

## WordPress Playground SQLite Smoke

Run the committed Playground smoke from the repository worktree root:

```sh
npx @wp-playground/cli@latest run-blueprint --blueprint=indexer/playground/sqlite-smoke-blueprint.json --mount="$(pwd)/indexer:/wordpress/wp-content/plugins/indexer" --blueprint-may-read-adjacent-files
```

The smoke activates the mounted `indexer` plugin in WordPress Playground,
asserts SQLite runtime evidence, inserts a small multilingual post set, indexes
through `WP_FTS_Indexer`, and searches through `WP_FTS_Searcher`. It probes
Polish stemming/detection, German detection, explicit language override, and
fallback behavior for text without detector evidence. It also covers the public
REST search route (`q`, `query`, invalid `mode`, missing query, and visible
result refill after hidden stale rows) plus WP-CLI `wp fts reindex` and
`wp fts search` when the Playground WP-CLI library is available.

## Optional BM25 Python Reference

Run the Python reference only when the environment has the optional virtualenv
and native library path used by the hardening contract:

```sh
LD_LIBRARY_PATH=/nix/store/f2q5ld1nipl8w1r2w8m6azhlm2varqgb-zlib-1.3.1/lib:/nix/store/cf1a53iqg6ncnygl698c4v0l8qam5a2q-gcc-14.3.0-lib/lib /home/claude/.cache/indexer-bm25s-venv/bin/python tests/bm25_lucene_reference.py
```

If `bm25s` is not installed, the script exits as an explicit optional skip.

## PHP Syntax Check

Run a syntax pass over source and tests:

```sh
find . -path ./vendor -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
```

## WordPress And MySQL Integration Harness

This branch includes an optional real WordPress/MySQL integration harness and a
quality skip-contract that proves the harness exits clearly when WordPress is
not configured. The real harness is composer-addressable:

```sh
composer test:integration:real
```

Configure it only against a disposable WordPress database, not a production
database. In the default unconfigured environment, `tests/run.php` still uses
MySQL and WP-CLI fakes/contracts for broad coverage and the real integration
contract safely reports an explicit skip.

## Diff And Status Checks

From the repository worktree root, always run:

```sh
git diff --check
git status --short --branch
```
