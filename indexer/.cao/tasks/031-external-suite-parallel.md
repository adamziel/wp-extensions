# Developer Task: Parallel External Multilingual Compliance Harness

Worktree: `/home/claude/indexer-external-suite`
Branch: `integration/external-suite`
Base: current `integration/multilingual` after Lanes 1-3 are merged

Add the external multilingual compliance harness without touching core integration files unless strictly necessary. This is parallel support work for the active objective:

> complete the work, test the heck out of it, find an existing unit test suite for multi-language full text searchers, and confirm we can pass it

## External Suite

Use Snowball's official multilingual stemming test data:

- Source: `https://github.com/snowballstem/snowball-data`
- Local checkout: `/home/claude/.cache/snowball-data`
- Each language has `voc.txt` input and `output.txt` expected stems.

Treat this as an analyzer/stemmer compliance suite for the multilingual full-text engine. Document that Lucene analysis/common is the broader analyzer reference, but do not claim Lucene unit-suite compatibility unless actually run.

## Required Work

1. Add a script or test entry point for external Snowball compliance, preferably under `tests/`.
2. It must read `SNOWBALL_DATA_DIR`; defaulting to `/home/claude/.cache/snowball-data` is acceptable for local runs, but document the env var.
3. It must test only languages the integrated pipeline actually supports via Snowball/wamania. Unsupported languages should be reported as skipped, not silently ignored.
4. It must compare `voc.txt` and `output.txt` line-by-line and fail on mismatches for supported languages.
5. Use the integrated `WP_FTS_SnowballStemmer` or pipeline directly enough that failures reflect our code, not a standalone duplicate implementation.
6. Add docs explaining how to run it and how to obtain Snowball data using `git clone`.
7. Run it against `/home/claude/.cache/snowball-data` and report pass/skip/fail per language.

## Constraints

- Do not use `curl` or `wget`.
- Do not read or output secrets such as `.env`, `*.pem`, `~/.ssh/*`, or AWS credential files.
- Keep normal `php tests/run.php` and `composer test` passing.

## Verification

Run and report:

- `php /home/claude/indexer-external-suite/tests/run.php`
- external harness command with `SNOWBALL_DATA_DIR=/home/claude/.cache/snowball-data`
- `composer test` if available
- `find /home/claude/indexer-external-suite -path /home/claude/indexer-external-suite/vendor -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l`
- `git -C /home/claude/indexer-external-suite diff --check`
- `git -C /home/claude/indexer-external-suite status --short --branch`

Commit the result on `integration/external-suite` and send the commit SHA, paths changed, command results, and exact pass/skip/fail language list back to terminal `da2963f2`.
