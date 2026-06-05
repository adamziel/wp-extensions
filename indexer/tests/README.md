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

Run the optional external BM25 reference harness:

```sh
python3 tests/bm25_lucene_reference.py
```

The reference harness requires `bm25s` in the active Python environment. It exits with
status 2 when `bm25s` is not installed so CI can keep it as an explicit opt-in job.
