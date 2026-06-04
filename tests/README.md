# Test Harness Notes

Run the normal no-WordPress suite:

```sh
php tests/run.php
```

The PHP harness automatically loads `tests/quality/*.php`, reports named tests
plus executed checks/scenarios, and enforces `WP_FTS_MIN_CHECKS`. This isolated
lane defaults the minimum to the existing 40-test suite; final quality
integration should raise the gate to at least 1500 executed checks/scenarios.

Run the optional external BM25 reference harness:

```sh
python3 tests/bm25_lucene_reference.py
```

The reference harness requires `bm25s` in the active Python environment. It exits with
status 2 when `bm25s` is not installed so CI can keep it as an explicit opt-in job.
