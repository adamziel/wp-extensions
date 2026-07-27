# Test Harness Notes

Run the normal no-WordPress suite:

```sh
php tests/run.php
```

The PHP harness automatically loads `tests/quality/*.php`, reports named tests
plus executed checks/scenarios, and enforces `WP_FTS_MIN_CHECKS`. Assertion
helpers count one executed check; generated loops can call `record_check()` with
an optional batch count. On this integrated branch, the standard harness and
Composer test entry points default to a minimum of 1500 executed checks/scenarios.
Set `WP_FTS_MIN_CHECKS` only when a local or CI lane needs an explicit override.

The analyzer source-lock quality test validates the synthetic no-op manifest in
`tests/fixtures/analyzer-source-locks/` and proves unsafe no-op metadata is
rejected. Run the verifier directly when changing manifests:

```sh
php tools/validate-analyzer-source-lock.php
```

Run the optional real WordPress/MySQL integration harness:

```sh
WP_FTS_WP_PATH=/path/to/wordpress \
WP_FTS_WP_CLI=wp \
php tests/integration/real-wordpress-mysql.php
```

`WP_FTS_WP_PATH` must point at an installed disposable WordPress site backed by
MySQL or MariaDB. `WP_FTS_WP_CLI` defaults to `wp`; set `WP_FTS_WP_URL` when the
site is multisite or otherwise needs an explicit URL. When WordPress or WP-CLI
is unavailable the command exits successfully with a `SKIP:` line so the normal
suite remains dependency-light.

The real harness creates generated temporary FTS tables, exercises `dbDelta()`
creation, binary `VARBINARY` terms and row postings through
`$wpdb->prepare()`, MySQL commit/rollback behavior, a simulated activation
path that verifies the exact physical schema, and a real `wp fts reindex`
process using `--require=tests/integration/wpcli-require.php`. It deletes its
temporary post and generated FTS tables in a cleanup block.

Run the guarded real WordPress/MySQL production-path proof against a disposable
site only:

```sh
WP_FTS_WP_PATH=/path/to/wordpress \
WP_FTS_WP_CLI=wp \
WP_FTS_WP_URL=http://127.0.0.1:8088 \
WP_FTS_PROOF_HTTP_BASE=http://127.0.0.1:8088 \
WP_FTS_MYSQL_PROOF_ALLOW_DISPOSABLE=1 \
php tests/integration/real-mysql-production-proof.php
```

The proof activates the plugin, asserts MySQL/MariaDB and InnoDB runtime
evidence, seeds multilingual and stale-hidden posts, reindexes through WP-CLI,
probes `wp fts search`, calls the public REST route over HTTP, checks REST alias
and invalid-mode behavior, captures sanitized row counts and EXPLAIN JSON, and
then deletes the generated proof posts. It exits with `SKIP:` unless both
`WP_FTS_WP_PATH` and the disposable-site opt-in are present.

The Docker helper provisions a throwaway MariaDB + WordPress + WP-CLI stack and
runs both real MySQL lanes:

```sh
tools/run-real-mysql-production-proof.sh
```

Run the optional concurrent indexing diagnostic:

```sh
WP_FTS_WP_PATH=/path/to/wordpress \
WP_FTS_CONCURRENT_WORKERS=4 \
WP_FTS_CONCURRENT_POSTS_PER_WORKER=3 \
php tests/integration/concurrent-indexing.php
```

This script creates worker-specific post types and starts concurrent `wp fts
reindex` processes against one generated FTS table prefix. It verifies that the
shared term postings contain every generated post. A failure here is evidence of
lost concurrent writes and should be investigated with the storage/concurrency
lane rather than hidden by weakening the test.
