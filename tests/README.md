# Test Harness Notes

Run the normal no-WordPress suite:

```sh
php tests/run.php
```

Run the optional external BM25 reference harness:

```sh
python3 tests/bm25_lucene_reference.py
```

The reference harness requires `bm25s` in the active Python environment. It exits with
status 2 when `bm25s` is not installed so CI can keep it as an explicit opt-in job.
