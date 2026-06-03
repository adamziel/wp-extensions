# Lane 3 Developer Task: Language-Aware Storage Backends

Worktree: `/home/claude/indexer-lanes/language-storage`
Branch: `lanes/language-storage`
Spec: `/home/claude/indexer/goal.md`
Shared contract: `/home/claude/indexer/.cao/tasks/010-parallel-lane-contract.md`

Primary focus:

- Update `WP_FTS_Storage` for language-aware docs, doc lengths, and meta stats.
- Implement the updated contract in in-memory storage.
- Implement the updated contract in file storage, preserving persistence and optimize behavior.
- Preserve compatibility enough that old single-language tests can be adapted cleanly.
- Ensure deleted/tombstoned docs are excluded from language-specific doc lengths and stats.
- Add or update snapshot helpers/tests for exact per-language state comparison.

Suggested owned files:

- `/home/claude/indexer-lanes/language-storage/src/StorageInterface.php`
- `/home/claude/indexer-lanes/language-storage/src/InMemoryStorage.php`
- `/home/claude/indexer-lanes/language-storage/src/FileStorage.php`
- Focused tests in `/home/claude/indexer-lanes/language-storage/tests/run.php`.

Do not spend time on MySQL storage beyond documenting the required method changes; Lane 5 owns that implementation.

Run tests before reporting:

- `php /home/claude/indexer-lanes/language-storage/tests/run.php`

Commit your lane changes before reporting. Report summary, commit SHA, absolute paths changed, commands/results, and any API assumptions Lane 4/5 must honor.

