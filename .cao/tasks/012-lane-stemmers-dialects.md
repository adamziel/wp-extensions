# Lane 2 Developer Task: Stemmers, Dialects, and Language Pipelines

Worktree: `/home/claude/indexer-lanes/stemmers-dialects`
Branch: `lanes/stemmers-dialects`
Spec: `/home/claude/indexer/goal.md`
Shared contract: `/home/claude/indexer/.cao/tasks/010-parallel-lane-contract.md`

Primary focus:

- Create the pluggable per-language analysis pipeline used by the analyzer.
- Add a pure-PHP Snowball stemmer adapter using `wamania/php-stemmer` where feasible. If dependency install is unavailable, update `composer.json` and provide a guarded adapter that fails gracefully until installed.
- Add per-language folding maps for at least Polish, German, Turkish-safe behavior, and a general Latin fallback that does not break when `iconv`/`intl` are missing.
- Add dialect normalization hooks and initial maps for `en-GB`/`en-US` spellings (`colour`/`color`, `-ise`/`-ize` examples) and `zh-Hant`/`zh-Hans` placeholders.
- Add a Polish stemmer strategy surface. Implement fold-only plus conservative suffix stopgap only if time permits; document Stempel/Morfologik as the next implementation if not.
- Keep output deterministic and byte-stable for term namespacing.

Suggested owned files:

- New files such as `/home/claude/indexer-lanes/stemmers-dialects/src/LanguagePipeline.php`, `/home/claude/indexer-lanes/stemmers-dialects/src/Stemmer.php`, `/home/claude/indexer-lanes/stemmers-dialects/src/Normalizer.php`.
- `/home/claude/indexer-lanes/stemmers-dialects/composer.json` if adding `wamania/php-stemmer`.
- Focused tests in `/home/claude/indexer-lanes/stemmers-dialects/tests/run.php`.

Minimize edits to `Analyzer.php`; if integration is necessary, keep it small and document it.

Run tests before reporting:

- `composer test` or `php /home/claude/indexer-lanes/stemmers-dialects/tests/run.php`

Commit your lane changes before reporting. Report summary, commit SHA, absolute paths changed, commands/results, dependency status, and remaining integration assumptions.

