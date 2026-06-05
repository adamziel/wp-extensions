# Task 045: Add Storage and Search Property Quality Tests

## Context

Read the shared contract first:

```text
/home/claude/indexer/.cao/tasks/042-quality-expansion-contract.md
```

Work from current trunk commit:

```text
581c3a4e8893c48f28d341d6f4e86deb7693420a
```

## Required Work

Create a `quality/storage-search-properties` branch/worktree and add high-diversity storage/search tests, preferably in:

```text
tests/quality/storage-search-properties.php
```

Target at least 500 meaningful executed checks from this lane.

Cover:

- In-memory and file storage parity for put/get/delete/optimize across many language partitions.
- Legacy storage call compatibility versus language-aware signatures.
- Tombstone behavior: hidden from doc lengths/meta/search, purged by optimize, no stat leakage.
- Reindex deltas: language changes, term distribution changes, document hash changes, same hash skip, delete then re-add.
- Per-language BM25 stats: doc count, avgdl, term frequency, document frequency, same normalized term isolated across languages.
- Boolean OR/AND, empty/stopword-only queries, duplicate terms, unknown terms, tie ordering, limit behavior.
- Differential checks: indexed search versus brute-force oracle over generated multilingual corpora.
- Incremental indexing versus full rebuild convergence over randomized operation sequences.
- File persistence/migration: version 1 docs into version 2 language records, reload parity, optimize after reload.

Use deterministic seeds. Report the seed(s) in failure messages.

## Acceptance

Run and report:

```bash
php tests/run.php
composer test
php -n tests/run.php
find . -path ./vendor -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
git diff --check
git status --short --branch
```

Commit the result and send the commit SHA plus approximate executed check contribution back to terminal `da2963f2`.
