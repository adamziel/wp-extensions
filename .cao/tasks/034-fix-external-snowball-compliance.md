# Task 034: Fix External Snowball Compliance Harness

## Context

Worktree: `/home/claude/indexer-external-suite`
Branch: `integration/external-suite`
Current commit: `d0b5814 Add Snowball compliance harness`

The normal project tests pass, but the required external multilingual suite does not pass yet. The harness uses official Snowball data from `/home/claude/.cache/snowball-data`.

Current failing command:

```bash
SNOWBALL_DATA_DIR=/home/claude/.cache/snowball-data php /home/claude/indexer-external-suite/tests/snowball-compliance.php
```

Observed result:

- `1 pass`
- `23 skip`
- `13 fail`
- Fail examples:
  - Danish line 1527 input `bbc's`, expected `bbc`, actual `bbc's`
  - Dutch line 2 input `á`, expected `á`, actual `a`
  - English line 439 input `added`, expected `add`, actual `ad`

Normal checks already passed:

```bash
php /home/claude/indexer-external-suite/tests/run.php
composer test
find /home/claude/indexer-external-suite -path /home/claude/indexer-external-suite/vendor -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
```

## Required Work

Investigate and fix the Snowball external suite so it is an honest compliance check and exits 0.

Do not paper over real mismatches by marking supported languages as skipped. It is acceptable to skip datasets only when the project genuinely does not support that language or algorithm variant, and the reason is documented.

Key things to verify:

- Whether `Wamania\Snowball\StemmerManager::stem()` expects language codes, algorithm names, class names, or a different API than currently used.
- Whether the harness is comparing raw Snowball stemming output against a normalized/folded project output by mistake.
- Whether `WP_FTS_SnowballStemmer` should map language codes to exact Wamania algorithm names.
- Whether official Snowball fixtures include algorithm variants that need explicit mapping or explicit skip.
- Whether supported language list in `WP_FTS_SnowballStemmer` is too broad for the installed Wamania package.

## Acceptance

Commit a focused fix on `integration/external-suite` and report the new commit SHA.

Run and report:

```bash
php /home/claude/indexer-external-suite/tests/run.php
composer test
SNOWBALL_DATA_DIR=/home/claude/.cache/snowball-data php /home/claude/indexer-external-suite/tests/snowball-compliance.php
find /home/claude/indexer-external-suite -path /home/claude/indexer-external-suite/vendor -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
git -C /home/claude/indexer-external-suite diff --check
git -C /home/claude/indexer-external-suite status --short --branch
```

When done, send results back to terminal `da2963f2` using the `send_message` tool.
