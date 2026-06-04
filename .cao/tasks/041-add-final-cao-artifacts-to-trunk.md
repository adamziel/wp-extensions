# Task 041: Add Final CAO Artifacts to Trunk

## Context

The final code/test progress has been merged and pushed to:

- `refs/heads/indexer/main`
- `refs/heads/trunk`

Both currently point to:

```text
3cde2d56de1102f4330aa0aa6d0d90b8cb843cba
```

Merge worktree:

```text
/home/claude/indexer-trunk-merge
branch: task/040-update-trunk
```

Some coordination artifacts from the final eight-hour push are still local-only in:

```text
/home/claude/indexer/.cao
```

They should also be on the indexer trunk branch so the branch contains the full implementation, verification, and review trail.

## Required Work

Copy these files from `/home/claude/indexer` into matching paths under `/home/claude/indexer-trunk-merge`, then commit and push the result to both `indexer/main` and `trunk`:

```text
.cao/tasks/034-fix-external-snowball-compliance.md
.cao/tasks/035-finish-integration-v3-lane6-external.md
.cao/tasks/036-fix-integration-optional-ctype.md
.cao/tasks/038-integrate-reviewed-external-suite-into-v3.md
.cao/tasks/040-update-trunk-with-final-progress.md
.cao/tasks/041-add-final-cao-artifacts-to-trunk.md
.cao/reviews/037-review-external-suite-fix.md
.cao/reviews/037-review-external-suite-fix-result.md
.cao/reviews/039-review-final-integration-v3.md
.cao/reviews/039-review-final-integration-v3-result.md
```

Do not include unrelated untracked files. Do not force-push.

## Acceptance

Run and report:

```bash
git -C /home/claude/indexer-trunk-merge status --short --branch
git -C /home/claude/indexer-trunk-merge diff --check
php /home/claude/indexer-trunk-merge/tests/run.php
composer test
php -n /home/claude/indexer-trunk-merge/tests/run.php
SNOWBALL_DATA_DIR=/home/claude/.cache/snowball-data php /home/claude/indexer-trunk-merge/tests/snowball-compliance.php
git -C /home/claude/indexer ls-remote github refs/heads/indexer/main refs/heads/trunk refs/heads/main
```

Expected:

- tests: `40/40 tests passed, 0 pending`
- Snowball: `2 pass, 35 skip, 0 fail`
- `indexer/main` and `trunk` point to the same new commit
- GitHub `main` remains unchanged at `289ad5a3bc31ad8666da1b304532bcc9fd2966f3`

Send the new commit SHA and results back to terminal `da2963f2` using `send_message`.
