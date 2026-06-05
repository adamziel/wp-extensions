# Task 055: Enforce 1500-Check Gate by Default

## Context

Final quality integration worktree:

```text
/home/claude/indexer-quality-integration
branch: integration/quality-expansion
current commit: 477462fd145895d48a1b11649bb5e6c02c5b9bd2
```

Final review result:

```text
/home/claude/indexer/.cao/reviews/054-review-quality-expanded-integration-result.md
Status: REQUIRED FIXES
```

Required issue:

The integrated suite reports `12233` checks/scenarios, but the default project test path still enforces only `minimum checks=40`.

Observed:

```text
php tests/run.php -> minimum checks=40
composer test -> minimum checks=40
WP_FTS_MIN_CHECKS=1500 php tests/run.php -> minimum checks=1500
```

For the final quality-expanded integration, the standard test entry points must enforce the requested `>=1500` check/scenario bar by default.

## Required Work

In `/home/claude/indexer-quality-integration`, make the integrated branch enforce at least `1500` checks/scenarios by default.

Acceptable approaches:

- Raise `WP_FTS_DEFAULT_MIN_CHECKS` to `1500`, or
- Set `WP_FTS_MIN_CHECKS=1500` in the standard Composer test command and ensure direct `php tests/run.php` has an appropriate final default.

Also update `tests/README.md` so it clearly describes the integrated branch's default quality gate. It should not imply this is still an isolated-lane default of 40.

Keep the change focused. Do not weaken the checks or hide failures as pending/skips.

## Acceptance

Commit the fix on `integration/quality-expansion`, push `refs/heads/indexer/quality-expanded`, and report the commit SHA.

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

Expected:

- normal, Composer, and `php -n` runs show `minimum checks=1500`
- `checks/scenarios=12233` or higher
- no failures
- no pending
- Snowball compliance `2 pass, 35 skip, 0 fail` when Wamania is available

When done, send results back to terminal `da2963f2` using `send_message`.
