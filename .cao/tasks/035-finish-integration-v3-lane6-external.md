# Task 035: Finish Integration V3 from Current Conflict State

## Context

Worktree: `/home/claude/indexer-integration-v3`
Branch: `integration/multilingual-v3`

Current state:

- Lanes 1-4 are merged.
- Lane 5 MySQL/WP-CLI is merged as commit `31a359e`.
- Lane 6 tests-quality merge is in progress.
- `tests/run.php` is conflicted.
- Current status was:

```text
## integration/multilingual-v3
M  .gitignore
A  tests/README.md
A  tests/bm25_lucene_reference.py
UU tests/run.php
```

Approved lane heads:

- Lane 1 analyzer-core: `89785dabe20f972b84016e56ceb795d6a9eba5d0`
- Lane 2 stemmers-dialects: `d0021b4b6ac130fa479145244968ff86dbeee055`
- Lane 3 language-storage: `549afc2c14a62ae037e6e5c76ae4aaf5f550ec88`
- Lane 4 search-stats: `e3145f973e657eba78dae90203d9ec30bf0430e8`
- Lane 5 mysql-wpcli: `9fdb2e5d67b169a1b7475bf6fdb18eda2df352cd`
- Lane 6 tests-quality: `d75ba335a530eacfc1c451f1c12ff55e165cca9b`

## Required Work

Resolve the current Lane 6 merge conflict and finish the integration commit.

Preserve the approved behaviors and tests from all lanes, especially:

- Lane 1 analyzer language scopes, optional extension guards, query occurrences, mixed-script/CJK tokenization.
- Lane 2 language pipeline, normalizer, stemmer arity fixes, uppercase non-ASCII fallback.
- Lane 3 language-aware in-memory/file storage.
- Lane 4 language-aware search stats and `WP_FTS_StorageCompat`.
- Lane 5 MySQL/WP-CLI language-aware schema/options.
- Lane 6 pending/enforced test harness upgrades and optional BM25 reference harness.

After Lane 6 is integrated, wait for or incorporate the fixed external Snowball suite from `/home/claude/indexer-external-suite` once task 034 is complete. If task 034 is not complete yet, commit the Lane 6 integration and clearly report that external-suite integration is still blocked.

## Acceptance

Run and report:

```bash
php /home/claude/indexer-integration-v3/tests/run.php
composer test
find /home/claude/indexer-integration-v3 -path /home/claude/indexer-integration-v3/vendor -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
git -C /home/claude/indexer-integration-v3 diff --check
git -C /home/claude/indexer-integration-v3 status --short --branch
```

If Snowball compliance is available in the integration tree, also run:

```bash
SNOWBALL_DATA_DIR=/home/claude/.cache/snowball-data php /home/claude/indexer-integration-v3/tests/snowball-compliance.php
```

Commit the result on `integration/multilingual-v3` and send the commit SHA plus command results back to terminal `da2963f2` using `send_message`.
