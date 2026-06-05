# Task 040: Put Final Indexer Progress on Trunk Branches

## Context

Repository remote:

```text
github -> https://github.com/adamziel/wp-extensions.git
```

The GitHub default branch is `main`, but that branch is the broader `wp-extensions` monorepo and has no common merge base with the standalone indexer work. Do **not** overwrite or force-push `main`.

Final reviewed indexer integration:

```text
/home/claude/indexer-integration-v3
branch: integration/multilingual-v3
commit: d649ec8966edde66b227fcb7f9697f16aeb77ec1
remote branch: github/indexer/integration-multilingual-v3
```

Final review:

```text
/home/claude/indexer/.cao/reviews/039-review-final-integration-v3-result.md
Status: APPROVED
```

Existing indexer trunk-like branch:

```text
github/indexer/main -> accd4a00bd672a6931b90876d66dae6c6d1bd826
```

`github/indexer/main` and final integration share base `b985faa7b97ae70775cb38e2f6f628025f6de7e2`; `indexer/main` has coordination task commits while final integration has implementation/test commits.

## Required Work

Create/update a local branch from `github/indexer/main`, merge `github/indexer/integration-multilingual-v3` into it, and push the merged result to:

- `refs/heads/indexer/main`
- `refs/heads/trunk`

This keeps the existing indexer coordination history and final implementation together, while avoiding any destructive push to the unrelated GitHub default `main`.

Do not force-push. Do not change GitHub `main`.

## Acceptance

Run and report:

```bash
git -C /home/claude/indexer fetch github --no-tags
git -C /home/claude/indexer status --short --branch
php /path/to/merged/worktree/tests/run.php
composer test
php -n /path/to/merged/worktree/tests/run.php
SNOWBALL_DATA_DIR=/home/claude/.cache/snowball-data php /path/to/merged/worktree/tests/snowball-compliance.php
find /path/to/merged/worktree -path /path/to/merged/worktree/vendor -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
git -C /path/to/merged/worktree diff --check
git -C /path/to/merged/worktree status --short --branch
git -C /home/claude/indexer ls-remote github refs/heads/indexer/main refs/heads/trunk refs/heads/main
```

Expected verification:

- tests: `40/40 tests passed, 0 pending`
- `php -n`: `40/40 tests passed, 0 pending`
- Snowball: `2 pass, 35 skip, 0 fail`
- clean worktree
- `indexer/main` and `trunk` point to the same new merged commit
- `main` remains unchanged at its existing monorepo commit

When done, send the merge commit SHA and command results back to terminal `da2963f2` using `send_message`.
