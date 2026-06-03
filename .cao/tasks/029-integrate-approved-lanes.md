# Developer Task: Integrate All Approved Multilingual FTS Lanes

Integration worktree: `/home/claude/indexer-integration`
Integration branch: `integration/multilingual`
Coordination repo: `/home/claude/indexer`
Updated spec: `/home/claude/indexer/goal.md`
Shared lane contract: `/home/claude/indexer/.cao/tasks/010-parallel-lane-contract.md`

All six lanes are individually reviewed and approved. Merge/reconcile them into one coherent implementation in `/home/claude/indexer-integration`.

## Approved Lane Heads

- Lane 1 analyzer-core: `89785dabe20f972b84016e56ceb795d6a9eba5d0`
- Lane 2 stemmers-dialects: `d0021b4b6ac130fa479145244968ff86dbeee055`
- Lane 3 language-storage: `549afc2c14a62ae037e6e5c76ae4aaf5f550ec88`
- Lane 4 search-stats: `e3145f973e657eba78dae90203d9ec30bf0430e8`
- Lane 5 mysql-wpcli: `9fdb2e5d67b169a1b7475bf6fdb18eda2df352cd`
- Lane 6 tests-quality: `d75ba335a530eacfc1c451f1c12ff55e165cca9b`

## Review Results To Preserve

- `/home/claude/indexer/.cao/reviews/020-review-analyzer-core-fix-result.md`
- `/home/claude/indexer/.cao/reviews/027-review-stemmer-arity-fix-result.md`
- `/home/claude/indexer/.cao/reviews/013-review-language-storage-result.md`
- `/home/claude/indexer/.cao/reviews/025-review-search-stats-fix-result.md`
- `/home/claude/indexer/.cao/reviews/028-review-mysql-wpcli-fix-result.md`
- `/home/claude/indexer/.cao/reviews/026-review-tests-quality-fix-result.md`

## Integration Requirements

1. Merge all approved behavior into one codebase. You may use git merge/cherry-pick/manual conflict resolution, but the final result must be coherent and committed on `integration/multilingual`.
2. Analyzer integration:
   - Preserve Lane 1 array-option API, `analyze_query_occurrences()`, `lang`-tagged content/query occurrences, HTML `lang` tracking, same-depth language-scope clearing, fallback optional-end handling, CJK/mixed-script tokenization, no double decode, and optional-extension guards.
   - Add Lane 2 `WP_FTS_LanguagePipeline`, `WP_FTS_Normalizer`, `WP_FTS_Stemmer`, Snowball adapter, dialect maps, conservative Polish fallback, and callable arity fixes.
   - Keep compatibility for legacy `analyze_query()` string-term output and legacy one-argument `stemmer` callables, including `metaphone`.
3. Storage/search/index integration:
   - Use Lane 3 language-aware storage contract/backends.
   - Use Lane 4 per-language index/search behavior and BM25 stats.
   - Use Lane 5 MySQL schema/storage and WP-CLI language options.
   - Choose one canonical helper for language/term namespacing, preserving `canonical_lang . "\x1e" . term`.
4. Tests:
   - Integrate Lane 6 test harness improvements.
   - Convert pending tests to enforced where the integrated implementation now satisfies them.
   - Keep only genuinely out-of-scope quality gates pending, such as live Cranfield/nDCG or optional `bm25s` if not installed.
5. Do not add automatic `save_post` or other indexing hooks.
6. Do not read or output secrets such as `.env`, `*.pem`, `~/.ssh/*`, or AWS credential files.

## Verification

Run and report:

- `php /home/claude/indexer-integration/tests/run.php`
- `composer test` if available
- `find /home/claude/indexer-integration -path /home/claude/indexer-integration/vendor -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l`
- `git -C /home/claude/indexer-integration diff --check`
- `git -C /home/claude/indexer-integration status --short --branch`

If tests fail due to a real integration defect, fix it. If an acceptance gate remains pending, document why it is still pending.

Commit the integrated result on `integration/multilingual` and report the commit SHA, changed absolute paths, commands/results, unresolved risks, and any pending gates.
