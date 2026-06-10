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

## Polish Analyzer Pack Validation

Validate the opt-in Polish Morfologik/PoliMorf fixture pack directly:

```sh
php tools/validate-analyzer-pack.php resources/analyzer-packs/pl-morfologik-polimorf-fixture/manifest.json
php -n tools/validate-analyzer-pack.php resources/analyzer-packs/pl-morfologik-polimorf-fixture/manifest.json
```

The validator checks manifest shape, runtime row normalization, duplicate rows,
ambiguous no-op handling, and declared checksums for the bundled fixture pack.

## Polish Verified Stemmer Fixtures

The opt-in Polish verified stemmer slice has a standalone fixture validator:

```sh
php tests/polish-verified-stemmer-fixtures.php
php -n tests/polish-verified-stemmer-fixtures.php
```

The same fixture rows are also covered by `tests/run.php` through
`tests/quality/polish-verified-stemmer.php`.

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
With the current official Snowball data checkout, a source tree without
`vendor/` should report `0 pass, 37 skip, 0 fail`; after installing dependencies
from `composer.lock`, only Catalan and Dutch Porter should pass, for
`2 pass, 35 skip, 0 fail`.

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

## Real MySQL Production-Path Proof

Use the disposable Docker lane when Docker image pulls and the local daemon are
available:

```sh
tools/run-real-mysql-production-proof.sh
```

The helper copies this plugin into a temporary directory, installs Composer
dependencies there, starts WordPress on MariaDB, activates the plugin, runs the
existing real-MySQL integration harness, and then runs the production-path proof.
The proof writes only sanitized evidence: source SHA, MySQL/MariaDB runtime,
InnoDB table engines, WP-CLI/REST probe status, row counts, EXPLAIN JSON, and
timing summaries. It destroys the Docker volume and temporary plugin copy on
exit.

For an already installed disposable WordPress site backed by MySQL/MariaDB, run:

```sh
WP_FTS_WP_PATH=/path/to/wordpress \
WP_FTS_WP_CLI=wp \
WP_FTS_WP_URL=http://127.0.0.1:8088 \
WP_FTS_PROOF_HTTP_BASE=http://127.0.0.1:8088 \
WP_FTS_MYSQL_PROOF_ALLOW_DISPOSABLE=1 \
php tests/integration/real-mysql-production-proof.php
```

Do not set `WP_FTS_MYSQL_PROOF_ALLOW_DISPOSABLE=1` for production or shared
staging data. Without that explicit opt-in the proof exits with `SKIP:`.

## Diff And Status Checks

From the repository worktree root, always run:

```sh
git diff --check
git status --short --branch
```
