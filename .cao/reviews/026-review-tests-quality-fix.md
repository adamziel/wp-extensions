# Reviewer Task: Lane 6 Tests/Quality Fix Review

Review lane: Tests/quality review fix
Worktree: `/home/claude/indexer-lanes/tests-quality`
Branch: `lanes/tests-quality`
Original lane commit: `bd75666580a70c2ab2c1bafad45af74e5bee81a4`
Fix commit: `d75ba335a530eacfc1c451f1c12ff55e165cca9b`

Authoritative inputs:

- Updated spec: `/home/claude/indexer/goal.md`
- Shared lane contract: `/home/claude/indexer/.cao/tasks/010-parallel-lane-contract.md`
- Original lane task: `/home/claude/indexer/.cao/tasks/016-lane-tests-quality.md`
- Prior review result: `/home/claude/indexer/.cao/reviews/016-review-tests-quality-result.md`
- Fix task: `/home/claude/indexer/.cao/tasks/021-fix-tests-quality-review.md`

Changed files since original lane commit:

- `/home/claude/indexer-lanes/tests-quality/.gitignore`
- `/home/claude/indexer-lanes/tests-quality/tests/run.php`
- `/home/claude/indexer-lanes/tests-quality/tests/bm25_lucene_reference.py`

Supervisor verification after fix:

- `php /home/claude/indexer-lanes/tests-quality/tests/run.php` -> exit 0, `10/15 tests passed, 5 pending in 1.535s`
- `php -l /home/claude/indexer-lanes/tests-quality/tests/run.php` -> no syntax errors
- `python3 -m py_compile /home/claude/indexer-lanes/tests-quality/tests/bm25_lucene_reference.py` -> passed
- `python3 /home/claude/indexer-lanes/tests-quality/tests/bm25_lucene_reference.py` -> exit 2 because `bm25s` is not installed, matching documented optional-dependency behavior
- `git -C /home/claude/indexer-lanes/tests-quality diff --check` -> clean
- Branch was clean at `d75ba335a530eacfc1c451f1c12ff55e165cca9b`.

Review focus:

1. Confirm T8 query fixture now requests language-aware query occurrences and can become enforced after Lane 1/Lane 2 analyzer APIs are merged.
2. Confirm the analyzer test adapter handles array-option signatures and string-language signatures correctly.
3. Confirm fake language partition storage advertises Lane 4-compatible language-aware `put_doc()` and `add_meta()` signatures while preserving legacy behavior.
4. Confirm the optional `bm25s` harness maps doc IDs correctly with non-index-like IDs and still exits 2 when `bm25s` is unavailable.
5. Confirm generated `__pycache__/` files are ignored/not committed.
6. Check pending tests still represent real not-yet-merged features and do not hide regressions in this lane.

Return `APPROVED` only if there are no required fixes for this lane after the fix commit. Otherwise return concrete required fixes with absolute paths and line references.
