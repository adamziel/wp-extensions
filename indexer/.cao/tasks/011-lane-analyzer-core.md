# Lane 1 Developer Task: Analyzer Core and Language Extraction

Worktree: `/home/claude/indexer-lanes/analyzer-core`
Branch: `lanes/analyzer-core`
Spec: `/home/claude/indexer/goal.md`
Shared contract: `/home/claude/indexer/.cao/tasks/010-parallel-lane-contract.md`
Review result: `/home/claude/indexer/.cao/reviews/001-review-v1-result.md`

Primary focus:

- Upgrade `WP_FTS_Analyzer` so content analysis can emit occurrences with `term`, `weight`, and `lang`.
- Add query analysis with explicit language options, while preserving compatibility where practical.
- Resolve document/query language from options first, then defaults; leave multilingual-plugin detection hooks as stubs if WordPress integration is unavailable.
- Track HTML `lang` attributes through the processor and fallback parser well enough for mixed-language documents.
- Keep the `WP_HTML_Processor` invariant: only read `#text` tokens.
- Fix the review issue: do not HTML-decode processor text a second time.
- Fix the review issue: guard optional extension calls (`iconv`, `mb_convert_encoding`, `Transliterator`, `mb_*`) or declare requirements and add runtime checks.
- Add script-aware tokenization, including CJK bigrams and mixed-script runs; keep CJK 1-2 char tokens despite the normal min length.

Suggested owned files:

- `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php`
- New analyzer/language helper files if useful.
- Focused tests in `/home/claude/indexer-lanes/analyzer-core/tests/run.php`.

Avoid doing full stemmer implementation; call a pluggable pipeline/stemmer hook where needed.

Run tests before reporting:

- `php /home/claude/indexer-lanes/analyzer-core/tests/run.php`
- Any targeted no-extension check you can run safely, such as `php -n ...`, if compatible with your changes.

Commit your lane changes before reporting. Report summary, commit SHA, absolute paths changed, commands/results, and remaining integration assumptions.

