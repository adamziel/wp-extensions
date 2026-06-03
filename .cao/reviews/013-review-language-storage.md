# Reviewer Task: Lane 3 Language-Aware Storage

Review lane: Language-aware storage
Worktree: `/home/claude/indexer-lanes/language-storage`
Branch: `lanes/language-storage`
Commit: `549afc2c14a62ae037e6e5c76ae4aaf5f550ec88`

Authoritative inputs:

- Updated spec: `/home/claude/indexer/goal.md`
- Shared lane contract: `/home/claude/indexer/.cao/tasks/010-parallel-lane-contract.md`
- Lane task: `/home/claude/indexer/.cao/tasks/013-lane-language-storage.md`

Changed files:

- `/home/claude/indexer-lanes/language-storage/src/StorageInterface.php`
- `/home/claude/indexer-lanes/language-storage/src/InMemoryStorage.php`
- `/home/claude/indexer-lanes/language-storage/src/FileStorage.php`
- `/home/claude/indexer-lanes/language-storage/src/MysqlStorage.php`
- `/home/claude/indexer-lanes/language-storage/tests/run.php`

Supervisor verification:

- `php /home/claude/indexer-lanes/language-storage/tests/run.php` -> `13/13 tests passed in 0.423s`
- Branch was clean at `549afc2c14a62ae037e6e5c76ae4aaf5f550ec88`.

Review focus:

1. Check the updated storage interface against the shared contract and updated spec.
2. Check in-memory/file per-language doc lengths, meta, tombstones, optimize, and legacy compatibility shims.
3. Check version 2 file persistence and version 1 migration behavior.
4. Check whether `add_meta` compatibility can drift from canonical doc-derived stats.
5. Check MySQL signature adjustments and whether temporary throws are safe until Lane 5.
6. Identify likely merge conflicts or integration assumptions with analyzer/search/MySQL lanes.

Return `APPROVED` only if there are no required fixes for this lane. Otherwise return concrete required fixes with absolute paths and line references.
