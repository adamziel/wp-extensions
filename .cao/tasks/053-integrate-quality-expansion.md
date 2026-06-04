# Task 053: Integrate Approved Quality Expansion

## Context

Current trunk:

```text
/home/claude/indexer-trunk-merge
branch: task/040-update-trunk
commit: 581c3a4e8893c48f28d341d6f4e86deb7693420a
remote refs: github/indexer/main and github/trunk
```

Quality branches:

```text
quality/harness-metrics -> 33093f22973e5682964b1ac5d64a32c78794827b
quality/external-reference-suite -> b3142785674d96721e68a9d4b24edf111f3b815f
quality/mysql-wpcli-contracts -> 47029afff81052cac96cd2f4731e79016d51ee4e
quality/storage-search-properties -> 82c7f7744714177b91658b80bc824db3d7582929
quality/analyzer-language-corpus -> 505bda3dfe9e37823cf631b2f849fd345cb63603
```

Already approved:

```text
/home/claude/indexer/.cao/reviews/048-review-quality-harness-metrics-result.md
/home/claude/indexer/.cao/reviews/049-review-quality-external-reference-suite-result.md
/home/claude/indexer/.cao/reviews/050-review-quality-mysql-wpcli-contracts-result.md
```

Pending before this task may proceed:

```text
/home/claude/indexer/.cao/reviews/051-review-quality-storage-search-properties-result.md
/home/claude/indexer/.cao/reviews/052-review-quality-analyzer-language-corpus-result.md
```

Do not integrate a branch unless its review result is APPROVED. The analyzer branch changes source files as well as tests, so review 052 approval is mandatory.

## Required Work

Create a new integration worktree/branch from current trunk:

```text
/home/claude/indexer-quality-integration
branch: integration/quality-expansion
```

Merge approved branches in this order:

1. `quality/harness-metrics`
2. `quality/storage-search-properties` if review 051 approved
3. `quality/external-reference-suite`
4. `quality/mysql-wpcli-contracts`
5. `quality/analyzer-language-corpus` if review 052 approved

Conflict handling:

- `tests/run.php`: prefer the harness metrics discovery/check-count implementation as the shared authority.
- Lane-specific tests should live in `tests/quality/*.php`.
- Remove one-off guarded includes from individual lanes if the harness discovery makes them redundant.
- Preserve all reviewed test files.
- Preserve analyzer source changes only if review 052 approved them.

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
```

Expected minimum evidence:

- No failed tests.
- No pending tests.
- `checks/scenarios >= 1500` in normal, composer, and `php -n` runs.
- With local Wamania installed, Snowball compliance should show `2 pass, 35 skip, 0 fail`; without Wamania it may show `0 pass, 37 skip, 0 fail` only in isolated worktrees. Final trunk verification should install Composer dependencies so the two real Snowball passes are exercised.

Commit the integration and push:

```text
refs/heads/indexer/quality-expanded
```

Send the commit SHA, named test count, checks/scenarios count, and command results back to terminal `da2963f2` using send_message.
