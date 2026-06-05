# Review 037: External Snowball Suite Fix

## Review Target

Worktree: `/home/claude/indexer-external-suite`
Branch: `integration/external-suite`
Commit: `691d9f77404c749b17061a25c0e37179eac4e4d5`

GitHub branch pushed:

```text
indexer/external-suite
```

## Context

This branch adds and fixes an external multilingual compliance harness using the official Snowball data checkout at:

```text
/home/claude/.cache/snowball-data
```

The original harness failed with `1 pass, 23 skip, 13 fail`. The fix now exits 0 with:

```text
Summary: 2 pass, 35 skip, 0 fail
PASS languages: Catalan [catalan] (ca), Dutch Porter [dutch_porter] (nl)
FAIL languages: (none)
```

The key review question is whether those skips are legitimate and transparently documented, or whether supported-language failures are being hidden.

## Changed Files

From `git show --stat HEAD`:

```text
docs/snowball-compliance.md
src/Stemmer.php
tests/run.php
tests/snowball-compliance.php
```

## Verification Already Run

```bash
php /home/claude/indexer-external-suite/tests/run.php
composer test
SNOWBALL_DATA_DIR=/home/claude/.cache/snowball-data php /home/claude/indexer-external-suite/tests/snowball-compliance.php
find /home/claude/indexer-external-suite -path /home/claude/indexer-external-suite/vendor -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
git -C /home/claude/indexer-external-suite diff --check
git -C /home/claude/indexer-external-suite status --short --branch
```

Observed results:

- normal tests: `25/25 tests passed`
- composer test: `25/25 tests passed`
- Snowball compliance: `2 pass, 35 skip, 0 fail`
- PHP syntax checks: no syntax errors
- diff check: clean
- status: clean

## Please Review

Check whether:

- The Snowball harness is an honest external compliance check against the official data.
- `WP_FTS_SnowballStemmer` support mapping matches the actual `wamania/php-stemmer` behavior.
- Skipping languages where Wamania exposes a class but does not match current official Snowball data is acceptable and clearly documented.
- Catalan and Dutch Porter are genuinely tested and passing against official `voc.txt`/`output.txt`.
- The harness exits non-zero for real failures in supported datasets.
- The docs explain how to run the harness and what pass/skip means.
- No unrelated code changes were introduced.

Write the result to:

```text
/home/claude/indexer/.cao/reviews/037-review-external-suite-fix-result.md
```

Return `APPROVED` only if no required fixes remain.
