# Developer Task: Fallback Integration for Lanes 4-6 and External Suite

Worktree: `/home/claude/indexer-integration-v2`
Branch: `integration/multilingual-v2`
Base commit: `4be6a59` (`integration/multilingual` after approved Lanes 1-3)

This is a fallback integration path because `/home/claude/indexer-integration` is mid-conflict while merging Lane 4. Do not edit that original integration worktree.

## Goal

Merge the remaining approved lanes and external multilingual compliance harness into `/home/claude/indexer-integration-v2`:

- Lane 4 search-stats: `e3145f973e657eba78dae90203d9ec30bf0430e8`
- Lane 5 mysql-wpcli: `9fdb2e5d67b169a1b7475bf6fdb18eda2df352cd`
- Lane 6 tests-quality: `d75ba335a530eacfc1c451f1c12ff55e165cca9b`
- External suite task: `/home/claude/indexer/.cao/tasks/030-add-external-multilingual-suite.md`

## Merge Guidance

The base already contains approved Lanes 1-3. Preserve them as authoritative for:

- Analyzer language API, `analyze_query_occurrences()`, HTML `lang` tracking, optional-end handling, CJK/mixed-script tokenization, no double-decode, and optional-extension guards.
- Language pipeline, normalizer, stemmer adapters, Snowball/wamania support, dialect maps, conservative Polish fallback, and custom stemmer arity compatibility.
- Language-aware in-memory/file storage contract and persistence behavior.

Add from Lane 4:

- Per-language index/search behavior, BM25 stats, `StorageCompat`, term namespace behavior, and tests.

Add from Lane 5:

- MySQL language-aware schema/storage, WP-CLI language options, fake `$wpdb` coverage, and tests.

Add from Lane 6:

- Test harness hardening, optional BM25 reference harness if useful, pending/enforced gate behavior adapted to the integrated implementation.

External suite:

- Add a Snowball-data compliance harness using `SNOWBALL_DATA_DIR`, defaulting locally to `/home/claude/.cache/snowball-data` if no env var is set.
- Compare `voc.txt` to `output.txt` line-by-line for supported Snowball languages.
- Report pass/skip/fail per language.
- Fail on mismatches for supported languages.

## Verification

Run and report:

- `php /home/claude/indexer-integration-v2/tests/run.php`
- `composer test` if available
- External Snowball harness with `SNOWBALL_DATA_DIR=/home/claude/.cache/snowball-data`
- `find /home/claude/indexer-integration-v2 -path /home/claude/indexer-integration-v2/vendor -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l`
- `git -C /home/claude/indexer-integration-v2 diff --check`
- `git -C /home/claude/indexer-integration-v2 status --short --branch`

Commit the integrated result on `integration/multilingual-v2` and send the commit SHA, changed absolute paths, command results, pass/skip/fail external language list, and remaining risks back to terminal `da2963f2`.
