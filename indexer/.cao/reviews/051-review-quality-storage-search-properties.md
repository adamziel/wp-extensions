# Review 051: Quality Storage Search Properties

## Target

Worktree: `/home/claude/indexer-quality-lanes/storage-search-properties`
Branch: `quality/storage-search-properties`
Commit: `82c7f7744714177b91658b80bc824db3d7582929`
Remote: `indexer/quality-storage-search-properties`

## Context

This lane should add meaningful storage/search property coverage under `tests/quality/storage-search-properties.php`. It relies on the harness metrics discovery lane for final integration.

## Reported Evidence

- Adds only `tests/quality/storage-search-properties.php`.
- Base lane run: `40/40 tests, 0 pending` because discovery is not present in this isolated lane.
- Discovery simulation with harness: `46/46 tests, 0 pending`, `10,729` checks total.
- Approximate lane contribution: `2,088` checks from six storage/search property tests.

## Review Focus

Check whether:

- Tests are meaningful and not inflated.
- In-memory/file storage parity, tombstones, optimize, legacy calls, reindex deltas, BM25 partitions, boolean search, brute-force oracle, and randomized incremental-vs-full rebuild behavior are covered.
- Tests can integrate cleanly with `tests/quality/*.php` discovery.
- There are no hidden dependencies or flaky random seeds.

Write result to:

```text
/home/claude/indexer/.cao/reviews/051-review-quality-storage-search-properties-result.md
```

Return APPROVED only if no required fixes remain.
