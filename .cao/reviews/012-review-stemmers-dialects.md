# Reviewer Task: Lane 2 Stemmers and Dialects

Review lane: Stemmers/dialects language pipeline
Worktree: `/home/claude/indexer-lanes/stemmers-dialects`
Branch: `lanes/stemmers-dialects`
Commit: `476ded5eb2eb90e522c5522c93503cb2a37b293d`

Authoritative inputs:

- Updated spec: `/home/claude/indexer/goal.md`
- Shared lane contract: `/home/claude/indexer/.cao/tasks/010-parallel-lane-contract.md`
- Lane task: `/home/claude/indexer/.cao/tasks/012-lane-stemmers-dialects.md`
- Prior review findings: `/home/claude/indexer/.cao/reviews/001-review-v1-result.md`

Changed files:

- `/home/claude/indexer-lanes/stemmers-dialects/composer.json`
- `/home/claude/indexer-lanes/stemmers-dialects/src/Analyzer.php`
- `/home/claude/indexer-lanes/stemmers-dialects/src/LanguagePipeline.php`
- `/home/claude/indexer-lanes/stemmers-dialects/src/Normalizer.php`
- `/home/claude/indexer-lanes/stemmers-dialects/src/Stemmer.php`
- `/home/claude/indexer-lanes/stemmers-dialects/src/bootstrap.php`
- `/home/claude/indexer-lanes/stemmers-dialects/tests/run.php`

Supervisor verification:

- `php /home/claude/indexer-lanes/stemmers-dialects/tests/run.php` -> `14/14 tests passed in 0.471s`
- Branch was clean at `476ded5eb2eb90e522c5522c93503cb2a37b293d`.

Review focus:

1. Check per-language normalization/folding behavior, especially Polish, German, Turkish-safe handling, and Latin fallback without `iconv`/`intl`.
2. Check dialect normalization behavior and whether maps are deterministic and reversible enough for index/query parity.
3. Check `wamania/php-stemmer` adapter dependency behavior with and without installed vendor files.
4. Check the conservative Polish fallback is correctly documented and not over-claiming Stempel/Morfologik support.
5. Check analyzer integration remains compatible with existing indexer/searcher and likely Lane 1 merge.
6. Identify likely merge conflicts with Lane 1 analyzer-core and Lane 4 search-stats.

Return `APPROVED` only if there are no required fixes for this lane. Otherwise return concrete required fixes with absolute paths and line references.

