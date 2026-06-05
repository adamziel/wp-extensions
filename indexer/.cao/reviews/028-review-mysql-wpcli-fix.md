# Reviewer Task: Lane 5 MySQL/WP-CLI Fix Review

Review lane: MySQL/WP-CLI review fix
Worktree: `/home/claude/indexer-lanes/mysql-wpcli`
Branch: `lanes/mysql-wpcli`
Original lane commit: `9cf35971b937f20b7359cc3b8e55f28c351dd7ab`
Fix commit: `9fdb2e5d67b169a1b7475bf6fdb18eda2df352cd`

Authoritative inputs:

- Prior review result: `/home/claude/indexer/.cao/reviews/015-review-mysql-wpcli-result.md`
- Fix task: `/home/claude/indexer/.cao/tasks/022-fix-mysql-wpcli-review.md`
- Original lane task: `/home/claude/indexer/.cao/tasks/015-lane-mysql-wpcli.md`
- Updated spec: `/home/claude/indexer/goal.md`

Supervisor verification:

- `php /home/claude/indexer-lanes/mysql-wpcli/tests/run.php` -> `16/16 tests passed in 0.389s`
- `find /home/claude/indexer-lanes/mysql-wpcli -path /home/claude/indexer-lanes/mysql-wpcli/vendor -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l` -> no syntax errors
- `git -C /home/claude/indexer-lanes/mysql-wpcli diff --check` -> clean
- Branch was clean at `9fdb2e5d67b169a1b7475bf6fdb18eda2df352cd`.

Review focus:

1. Confirm `WP_FTS_Indexer` passes resolved document language into analyzer calls using Lane 1-recognized keys while preserving HTML element `lang` overrides and plain-occurrence fallback.
2. Confirm `WP_FTS_Searcher` passes resolved query language, requests query occurrences when available, and falls back to plain terms namespaced with the explicit query language.
3. Confirm WP-CLI `--lang` / `--language` flows through indexing and search analysis.
4. Confirm MySQL schema/storage behavior, fake `$wpdb` tests, and no automatic hooks were not regressed.
5. Identify remaining live-MySQL/dbDelta risks and merge notes with Lane 3/4.

Return `APPROVED` only if there are no required fixes for this lane after the fix commit. Otherwise return concrete required fixes with absolute paths and line references.
