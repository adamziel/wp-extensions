# Developer Task: Rescue Integration From Clean Lanes 1-4 Base

Worktree: `/home/claude/indexer-integration-v3`
Branch: `integration/multilingual-v3`
Base commit: `a18abc9` (approved Lanes 1-4 integrated)

The earlier integration worktrees are mid-conflict while merging Lane 5. Use this clean worktree instead and do not edit:

- `/home/claude/indexer-integration`
- `/home/claude/indexer-integration-v2`
- `/home/claude/indexer-external-suite`

## Goal

Finish the integrated implementation from the clean Lanes 1-4 base:

1. Merge approved Lane 5 MySQL/WP-CLI commit:
   - `9fdb2e5d67b169a1b7475bf6fdb18eda2df352cd`
2. Merge approved Lane 6 tests-quality commit:
   - `d75ba335a530eacfc1c451f1c12ff55e165cca9b`
3. Add the external Snowball multilingual compliance harness from:
   - `/home/claude/indexer/.cao/tasks/030-add-external-multilingual-suite.md`

## Merge Guidance

Preserve the existing base as authoritative for:

- Lane 1 analyzer APIs, language tracking, optional-end handling, CJK tokenization, extension guards, no double decode.
- Lane 2 language pipeline, normalizer, stemmer adapters, custom stemmer arity behavior.
- Lane 3 language-aware in-memory/file storage.
- Lane 4 `Indexer`, `Searcher`, `StorageCompat`, `TermNamespace`, per-language BM25 stats, and analyzer language propagation.

When merging Lane 5:

- Prefer Lane 3's storage contract/backends for in-memory and file storage unless Lane 5 contains strictly necessary MySQL/WP-CLI additions.
- Preserve Lane 5's `MysqlStorage` schema/operations, `WPCLICommand` language options, fake `$wpdb` tests, and no automatic hooks.
- Avoid duplicate language helpers if possible. The integrated base already has `WP_FTS_TermNamespace`; use that unless Lane 5's `WP_FTS_Language` is truly needed.
- Keep Lane 4's fixed `Indexer`/`Searcher` analyzer language behavior while adding Lane 5 MySQL/WP-CLI behavior.

When merging Lane 6:

- Bring in test harness hardening and optional BM25 reference harness.
- Convert pending gates to enforced where integrated behavior now satisfies them.

External suite:

- Use `/home/claude/.cache/snowball-data` as `SNOWBALL_DATA_DIR`.
- Add a script or test entry point that compares supported Snowball languages' `voc.txt` to `output.txt`.
- Fail on mismatches for supported languages.
- Report unsupported languages as skipped.

## Verification

Run and report:

- `php /home/claude/indexer-integration-v3/tests/run.php`
- `composer test` if available
- external Snowball harness with `SNOWBALL_DATA_DIR=/home/claude/.cache/snowball-data`
- `find /home/claude/indexer-integration-v3 -path /home/claude/indexer-integration-v3/vendor -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l`
- `git -C /home/claude/indexer-integration-v3 diff --check`
- `git -C /home/claude/indexer-integration-v3 status --short --branch`

Commit the final integrated result on `integration/multilingual-v3`. Send the commit SHA, changed absolute paths, all command results, exact Snowball pass/skip/fail language list, and remaining risks to terminal `da2963f2`.
