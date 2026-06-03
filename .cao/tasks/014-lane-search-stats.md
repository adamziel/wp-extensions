# Lane 4 Developer Task: Indexer/Searcher Language Namespacing and BM25 Stats

Worktree: `/home/claude/indexer-lanes/search-stats`
Branch: `lanes/search-stats`
Spec: `/home/claude/indexer/goal.md`
Shared contract: `/home/claude/indexer/.cao/tasks/010-parallel-lane-contract.md`

Primary focus:

- Update `WP_FTS_Indexer` to accept language options, consume analyzer occurrences with `lang`, namespace terms, and maintain per-language doc length/meta deltas.
- Update `WP_FTS_Searcher` to resolve query language, namespace query terms, use per-language `N`, `avgdl`, and doc lengths, and preserve OR/AND semantics.
- Keep single-language-per-query as the default. If adding all-language search, make it opt-in and do not compare raw BM25 scores across language partitions.
- Ensure reindex/update/delete decrements old per-language stats correctly.
- Keep postings encoding unchanged.

Suggested owned files:

- `/home/claude/indexer-lanes/search-stats/src/Indexer.php`
- `/home/claude/indexer-lanes/search-stats/src/Searcher.php`
- Any small term namespace helper.
- Focused tests in `/home/claude/indexer-lanes/search-stats/tests/run.php`.

Use the shared storage contract even if Lane 3 is not merged yet; local adapters or compatibility shims are acceptable, but document them.

Run tests before reporting:

- `php /home/claude/indexer-lanes/search-stats/tests/run.php`

Commit your lane changes before reporting. Report summary, commit SHA, absolute paths changed, commands/results, and required integration points with Lane 1/3.

