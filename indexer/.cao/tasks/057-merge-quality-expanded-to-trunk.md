# Task 057: Merge Quality Expanded Integration to Trunk

## Context

Reviewed quality integration:

```text
worktree: /home/claude/indexer-quality-integration
branch: integration/quality-expansion
commit: cf22f1957a6b639db30e04cc4a6dba12ba210d44
remote: refs/heads/indexer/quality-expanded
```

Review approvals:

```text
/home/claude/indexer/.cao/reviews/048-review-quality-harness-metrics-result.md
/home/claude/indexer/.cao/reviews/049-review-quality-external-reference-suite-result.md
/home/claude/indexer/.cao/reviews/050-review-quality-mysql-wpcli-contracts-result.md
/home/claude/indexer/.cao/reviews/051-review-quality-storage-search-properties-result.md
/home/claude/indexer/.cao/reviews/052-review-quality-analyzer-language-corpus-result.md
/home/claude/indexer/.cao/reviews/054-review-quality-expanded-integration-result.md
/home/claude/indexer/.cao/reviews/056-review-quality-expanded-gate-fix-result.md
```

Current trunk/indexer branch before this task:

```text
refs/heads/indexer/main -> 581c3a4e8893c48f28d341d6f4e86deb7693420a
refs/heads/trunk -> 581c3a4e8893c48f28d341d6f4e86deb7693420a
```

## Required Work

Use `/home/claude/indexer-trunk-merge` or a new worktree from `github/indexer/main`.

Merge `github/indexer/quality-expanded` into the trunk branch.

Also copy the new task/review artifacts from `/home/claude/indexer` into the matching `.cao/` paths under the trunk worktree, including tasks/reviews 042-057 and their result files where present.

Push the final result without force to:

```text
refs/heads/indexer/main
refs/heads/trunk
```

Do not modify GitHub default `main`.

## Acceptance

Run and report:

```bash
php tests/run.php
composer test
php -n tests/run.php
WP_FTS_MIN_CHECKS=1500 php tests/run.php
SNOWBALL_DATA_DIR=/home/claude/.cache/snowball-data php tests/snowball-compliance.php
find . -path ./vendor -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
git diff --check
git status --short --branch
git -C /home/claude/indexer ls-remote github refs/heads/indexer/main refs/heads/trunk refs/heads/main refs/heads/indexer/quality-expanded
```

Expected:

- default normal, Composer, and `php -n` runs all show `minimum checks=1500`
- `checks/scenarios=12233` or higher
- no failures
- no pending tests
- Snowball compliance: `2 pass, 35 skip, 0 fail` when Wamania is available
- `indexer/main` and `trunk` point to the same new commit
- GitHub default `main` remains unchanged

Send commit SHA and command results to terminal `da2963f2`.
