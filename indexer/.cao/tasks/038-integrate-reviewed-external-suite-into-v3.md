# Task 038: Integrate Reviewed External Snowball Suite into Final V3 Branch

## Context

Final integration base:

- Worktree: `/home/claude/indexer-integration-v3`
- Branch: `integration/multilingual-v3`
- Current head: `7ab7207 Avoid ctype dependency in language canonicalization`

This branch has Lanes 1-6 merged and the optional `ctype` runtime fix.

Reviewed external-suite source:

- Worktree: `/home/claude/indexer-external-suite`
- Branch: `integration/external-suite`
- Reviewed commit: `691d9f77404c749b17061a25c0e37179eac4e4d5`
- Review result: `/home/claude/indexer/.cao/reviews/037-review-external-suite-fix-result.md`
- GitHub branch: `indexer/external-suite`

The reviewed external-suite fix passes:

```text
SNOWBALL_DATA_DIR=/home/claude/.cache/snowball-data php tests/snowball-compliance.php
Summary: 2 pass, 35 skip, 0 fail
```

An older integration branch `/home/claude/indexer-integration` has a different external harness that fails 13 languages. Do not use that failing harness as final. Use the reviewed support-boundary behavior from commit `691d9f77404c749b17061a25c0e37179eac4e4d5`.

## Required Work

Fold the reviewed external Snowball compliance suite into `/home/claude/indexer-integration-v3`.

Expected artifacts in final branch:

- Composer script for the Snowball compliance harness.
- Snowball compliance documentation.
- Snowball compliance PHP harness.
- Any needed `WP_FTS_SnowballStemmer` support mapping adjustments from the reviewed external-suite fix.
- Existing Lane 1-6 tests and behavior preserved.
- Optional `ctype` fix preserved.

Prefer a small focused commit on `integration/multilingual-v3`.

## Acceptance

Run and report:

```bash
php /home/claude/indexer-integration-v3/tests/run.php
composer test
php -n /home/claude/indexer-integration-v3/tests/run.php
SNOWBALL_DATA_DIR=/home/claude/.cache/snowball-data php /home/claude/indexer-integration-v3/tests/snowball-compliance.php
find /home/claude/indexer-integration-v3 -path /home/claude/indexer-integration-v3/vendor -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
git -C /home/claude/indexer-integration-v3 diff --check
git -C /home/claude/indexer-integration-v3 status --short --branch
```

Expected Snowball result:

```text
2 pass, 35 skip, 0 fail
```

Commit the result and send the commit SHA plus command results back to terminal `da2963f2` using `send_message`.
