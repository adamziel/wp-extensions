# Task 036: Fix Integration Optional ctype Runtime Failure

## Context

Worktree: `/home/claude/indexer-integration-v3`
Branch: `integration/multilingual-v3`
Current head: `89077d5 Restore lane 6 test quality gates`

The integrated branch now has Lanes 1-6 merged and normal tests pass:

```bash
php /home/claude/indexer-integration-v3/tests/run.php
composer test
```

Both reported `40/40 tests passed, 0 pending`.

However, the optional-extension-free run fails:

```bash
php -n /home/claude/indexer-integration-v3/tests/run.php
```

Failure pattern:

```text
Call to undefined function ctype_alpha()
```

Known failing call sites include:

- `/home/claude/indexer-integration-v3/src/Analyzer.php`
- `/home/claude/indexer-integration-v3/src/TermNamespace.php`
- callers through `/home/claude/indexer-integration-v3/src/Indexer.php`
- callers through `/home/claude/indexer-integration-v3/src/MysqlStorage.php`
- callers through `/home/claude/indexer-integration-v3/src/WPCLICommand.php`

This violates the approved analyzer/language pipeline optional-extension guard requirements.

## Required Work

Fix the integrated branch so language canonicalization does not require the optional `ctype` extension.

Do not remove the existing language validation semantics. Use a small ASCII-safe fallback/helper in the relevant integrated classes, or route through the already guarded normalizer/language helper if appropriate.

Keep the change focused to the integration branch. Do not modify lane worktrees.

## Acceptance

Commit the fix on `integration/multilingual-v3` and report the commit SHA.

Run and report:

```bash
php /home/claude/indexer-integration-v3/tests/run.php
composer test
php -n /home/claude/indexer-integration-v3/tests/run.php
find /home/claude/indexer-integration-v3 -path /home/claude/indexer-integration-v3/vendor -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
git -C /home/claude/indexer-integration-v3 diff --check
git -C /home/claude/indexer-integration-v3 status --short --branch
```

When done, send results back to terminal `da2963f2` using `send_message`.
