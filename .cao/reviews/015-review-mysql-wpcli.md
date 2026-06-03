# Reviewer Task: Lane 5 MySQL and WP-CLI

Review lane: MySQL storage and WP-CLI integration
Worktree: `/home/claude/indexer-lanes/mysql-wpcli`
Branch: `lanes/mysql-wpcli`
Commit: `9cf35971b937f20b7359cc3b8e55f28c351dd7ab`

Authoritative inputs:

- Updated spec: `/home/claude/indexer/goal.md`
- Shared lane contract: `/home/claude/indexer/.cao/tasks/010-parallel-lane-contract.md`
- Lane task: `/home/claude/indexer/.cao/tasks/015-lane-mysql-wpcli.md`

Changed files:

- `/home/claude/indexer-lanes/mysql-wpcli/src/FileStorage.php`
- `/home/claude/indexer-lanes/mysql-wpcli/src/InMemoryStorage.php`
- `/home/claude/indexer-lanes/mysql-wpcli/src/Indexer.php`
- `/home/claude/indexer-lanes/mysql-wpcli/src/Language.php`
- `/home/claude/indexer-lanes/mysql-wpcli/src/MysqlStorage.php`
- `/home/claude/indexer-lanes/mysql-wpcli/src/Searcher.php`
- `/home/claude/indexer-lanes/mysql-wpcli/src/StorageInterface.php`
- `/home/claude/indexer-lanes/mysql-wpcli/src/WPCLICommand.php`
- `/home/claude/indexer-lanes/mysql-wpcli/src/bootstrap.php`
- `/home/claude/indexer-lanes/mysql-wpcli/tests/run.php`

Supervisor verification:

- `php /home/claude/indexer-lanes/mysql-wpcli/tests/run.php` -> `13/13 tests passed in 0.361s`
- `php -l` over `indexer.php`, `src/*.php`, and `tests/run.php` -> no syntax errors
- `git -C /home/claude/indexer-lanes/mysql-wpcli diff --check` -> clean
- Branch was clean at `9cf35971b937f20b7359cc3b8e55f28c351dd7ab`.

Known untested areas from developer:

- No live MySQL/dbDelta run was available; schema and `$wpdb` calls are covered by a lightweight fake `$wpdb` only.
- Binary `VARBINARY` term matching, `LONGBLOB` round trips through real `$wpdb->prepare`, InnoDB transactions, `ON DUPLICATE KEY UPDATE`, and real `dbDelta()` migration formatting still need validation in WordPress/MySQL.
- Existing-table migrations from the old single-language schema are not integration-tested.

Review focus:

1. Check MySQL schema against the updated spec: `fts_terms`, `fts_docs`, doc-language lengths, and `fts_meta(lang,k,v)`.
2. Check exact binary term storage and language namespacing.
3. Check `$wpdb` prepared statements, transaction calls, and `ON DUPLICATE KEY UPDATE` behavior for obvious correctness issues.
4. Check WP-CLI language/status/type/limit/batch options and confirm no automatic hooks were added.
5. Check fake `$wpdb` tests are meaningful and not overfitting.
6. Identify likely merge conflicts with Lane 3 storage and Lane 4 search/index stats.

Return `APPROVED` only if there are no required fixes for this lane. Otherwise return concrete required fixes with absolute paths and line references.
