# Reviewer Task: Lane 4 Search Stats

Review lane: Search/index per-language stats
Worktree: `/home/claude/indexer-lanes/search-stats`
Branch: `lanes/search-stats`
Commit: `8f1130f0b071ff4f3b79471e2f1ec8cbf3d722e6`

Authoritative inputs:

- Updated spec: `/home/claude/indexer/goal.md`
- Shared lane contract: `/home/claude/indexer/.cao/tasks/010-parallel-lane-contract.md`
- Lane task: `/home/claude/indexer/.cao/tasks/014-lane-search-stats.md`

Changed files:

- `/home/claude/indexer-lanes/search-stats/src/Indexer.php`
- `/home/claude/indexer-lanes/search-stats/src/Searcher.php`
- `/home/claude/indexer-lanes/search-stats/src/StorageCompat.php`
- `/home/claude/indexer-lanes/search-stats/src/TermNamespace.php`
- `/home/claude/indexer-lanes/search-stats/src/bootstrap.php`
- `/home/claude/indexer-lanes/search-stats/tests/run.php`

Supervisor verification:

- `php /home/claude/indexer-lanes/search-stats/tests/run.php` -> `13/13 tests passed in 0.455s`
- Branch was clean at `8f1130f0b071ff4f3b79471e2f1ec8cbf3d722e6`.

Review focus:

1. Check term namespacing as `lang . "\x1e" . term` and canonical language handling.
2. Check indexer per-language doc lengths, primary language/hash behavior, and update/delete meta deltas.
3. Check searcher uses query language, language-specific doc lengths, language-specific `N`/`avgdl`, and preserves OR/AND semantics.
4. Check `WP_FTS_StorageCompat` method probing and fallback behavior against Lane 3's reported storage contract.
5. Check whether raw BM25 scores are kept within a single language partition unless explicitly opted into cross-language behavior.
6. Identify likely merge conflicts with Lane 1 analyzer-core and Lane 3 storage.

Return `APPROVED` only if there are no required fixes for this lane. Otherwise return concrete required fixes with absolute paths and line references.
