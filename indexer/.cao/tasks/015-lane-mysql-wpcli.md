# Lane 5 Developer Task: MySQL Storage and WP-CLI Integration

Worktree: `/home/claude/indexer-lanes/mysql-wpcli`
Branch: `lanes/mysql-wpcli`
Spec: `/home/claude/indexer/goal.md`
Shared contract: `/home/claude/indexer/.cao/tasks/010-parallel-lane-contract.md`

Primary focus:

- Update `WP_FTS_Storage_Mysql` for the updated language-aware schema:
  - `fts_terms` language-namespaced terms or equivalent composite key.
  - `fts_docs` with primary language and deletion/hash state.
  - `fts_meta` keyed by language and key.
  - Any extra doc-language length representation needed to satisfy the shared contract.
- Keep exact binary term matching.
- Use prepared statements and transactions where the current storage interface supports them.
- Improve table creation SQL for `dbDelta()` compatibility.
- Update WP-CLI commands to accept language, post type/status, limit/options as needed and avoid automatic hooks.
- Add lightweight fake `$wpdb` tests or syntax-level tests if real MySQL is not available.

Suggested owned files:

- `/home/claude/indexer-lanes/mysql-wpcli/src/MysqlStorage.php`
- `/home/claude/indexer-lanes/mysql-wpcli/src/WPCLICommand.php`
- `/home/claude/indexer-lanes/mysql-wpcli/indexer.php`
- Focused tests in `/home/claude/indexer-lanes/mysql-wpcli/tests/run.php`.

Run tests before reporting:

- `php /home/claude/indexer-lanes/mysql-wpcli/tests/run.php`
- Any `php -l` syntax checks over changed PHP files.

Commit your lane changes before reporting. Report summary, commit SHA, absolute paths changed, commands/results, and any MySQL behavior not integration-tested.

