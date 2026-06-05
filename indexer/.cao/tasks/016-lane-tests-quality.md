# Lane 6 Developer Task: Acceptance Tests and Quality Harness

Worktree: `/home/claude/indexer-lanes/tests-quality`
Branch: `lanes/tests-quality`
Spec: `/home/claude/indexer/goal.md`
Shared contract: `/home/claude/indexer/.cao/tasks/010-parallel-lane-contract.md`
Review result: `/home/claude/indexer/.cao/reviews/001-review-v1-result.md`

Primary focus:

- Expand the test suite toward the updated acceptance gates, especially T8 multilingual behavior.
- Add regression tests for the two review findings:
  - No fatal when optional extensions are absent or clear runtime checks exist.
  - No double entity decode on the `WP_HTML_Processor` path.
- Increase generated brute-force parity to at least 200 corpus/query combinations if runtime remains reasonable.
- Add per-language analyzer fixtures for English, Polish, German/Turkish-sensitive folding behavior, and CJK bigrams.
- Add mixed-language document tests with `lang` attributes.
- Add per-language BM25 stats tests that fail if global stats are used.
- Add external Lucene-IDF BM25 reference harness if feasible without making normal tests brittle; otherwise add a documented optional command.
- Keep tests runnable without a full WordPress install.

Suggested owned files:

- `/home/claude/indexer-lanes/tests-quality/tests/run.php`
- Optional new fixture/helper files under `/home/claude/indexer-lanes/tests/`.
- Optional scripts under `/home/claude/indexer-lanes/tests/` for external references.

It is acceptable for this lane to create failing tests that reflect the updated spec, but label them clearly if they depend on other lanes. Prefer making the current suite still runnable with pending-spec tests marked/skipped only when necessary.

Run tests before reporting:

- `php /home/claude/indexer-lanes/tests-quality/tests/run.php`
- Optional reference command if added.

Commit your lane changes before reporting. Report summary, commit SHA, absolute paths changed, commands/results, and which acceptance gates are now covered or intentionally pending.
