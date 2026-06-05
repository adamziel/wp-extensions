# Task 042: Quality Expansion Contract for Multilingual Indexer

## Context

Current trunk worktree:

```text
/home/claude/indexer-trunk-merge
branch: task/040-update-trunk
commit: 581c3a4e8893c48f28d341d6f4e86deb7693420a
remote refs: github/indexer/main and github/trunk
```

Current proof is not strong enough for a "100% functional / great quality / all done" claim:

- `tests/run.php` reports only 40 named tests.
- Some tests contain generated loops, but the harness does not report executed scenario/assertion volume.
- Multilingual FTS behavior needs broader scrutiny across Unicode, HTML language routing, analyzer normalization, stemming, storage consistency, search ranking, MySQL schema calls, WP-CLI args, and external reference behavior.

## Shared Goal

Build a materially stronger test suite that reports at least:

```text
>= 1500 executed checks/scenarios
0 failed
0 pending
```

Do not inflate numbers with trivial repeated checks. Scenario checks should vary input dimensions such as language, script, punctuation, HTML shape, storage backend, deletion/reindex path, query operator, document distribution, or reference fixture row.

## Branching

Use branches/worktrees from current trunk:

```bash
git -C /home/claude/indexer fetch github --no-tags
git -C /home/claude/indexer worktree add /home/claude/indexer-quality-lanes/<lane-name> 581c3a4e8893c48f28d341d6f4e86deb7693420a
git -C /home/claude/indexer-quality-lanes/<lane-name> switch -c quality/<lane-name>
```

## Test Architecture Contract

Preferred shape:

- Keep shared harness changes in `tests/run.php`.
- Add lane-specific tests under `tests/quality/*.php`.
- Lane-specific files should register tests using the existing `test_case()` helper once the harness supports discovery.
- The harness should report both named test count and executed check/scenario count.
- The final suite should fail if the executed check count drops below 1500.

If a lane starts before discovery support exists, write the test file against this intended API and include a short note in the result about the expected integration point.

## Mandatory Verification

Every final integrated branch must pass:

```bash
php tests/run.php
composer test
php -n tests/run.php
SNOWBALL_DATA_DIR=/home/claude/.cache/snowball-data php tests/snowball-compliance.php
find . -path ./vendor -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
git diff --check
git status --short --branch
```

Optional but expected when dependencies exist:

```bash
python3 tests/bm25_lucene_reference.py
```

## Completion Bar

Do not call the result "100% functional"; report evidence precisely. The desired outcome is a much stronger, review-approved quality gate that can support a serious claim about multilingual indexer behavior.
