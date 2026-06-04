# Task 046: Add External Reference and Corpus Quality Tests

## Context

Read the shared contract first:

```text
/home/claude/indexer/.cao/tasks/042-quality-expansion-contract.md
```

Work from current trunk commit:

```text
581c3a4e8893c48f28d341d6f4e86deb7693420a
```

Existing external source:

```text
/home/claude/.cache/snowball-data
```

## Required Work

Create a `quality/external-reference-suite` branch/worktree and add external/reference-oriented tests, preferably in:

```text
tests/quality/external-reference-suite.php
```

Target at least 300 meaningful executed checks from this lane, separate from the standalone Snowball harness line-pair count.

Cover:

- Sample official Snowball fixture rows for every advertised supported dataset, including Catalan and Dutch Porter.
- Negative support-boundary checks: languages deliberately not advertised must be reported as unsupported with documented reason, not silently stemmed as compliant.
- BM25 formula reference checks using a local deterministic implementation and/or the optional Python harness when `bm25s` exists.
- Lucene-style BM25 IDF/length-normalization examples using manually encoded reference values where licensing-safe and locally documented.
- Tokenization/reference corpora for multilingual snippets: Polish, German, Turkish, English dialect spelling, CJK bigrams, mixed-script documents.
- Ensure optional external dependencies skip explicitly and do not weaken the required local checks.

Do not fetch data with curl/wget. If additional references are needed, document them and use local fixtures only.

## Acceptance

Run and report:

```bash
php tests/run.php
composer test
php -n tests/run.php
SNOWBALL_DATA_DIR=/home/claude/.cache/snowball-data php tests/snowball-compliance.php
python3 tests/bm25_lucene_reference.py || true
find . -path ./vendor -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
git diff --check
git status --short --branch
```

Commit the result and send the commit SHA plus approximate executed check contribution back to terminal `da2963f2`.
