# Developer Task: Add External Multilingual Search/Analyzer Compliance Suite

Integration worktree: `/home/claude/indexer-integration`
Integration branch: `integration/multilingual`
Related integration task: `/home/claude/indexer/.cao/tasks/029-integrate-approved-lanes.md`

The active user objective is not just to merge lanes. The finished system must be heavily tested and must use an existing external multilingual full-text/analyzer test suite where possible.

## Existing Suite To Use

Use Snowball's official multilingual stemming test data as the concrete external compliance suite:

- Source: `https://github.com/snowballstem/snowball-data`
- The repository describes itself as "Test data for snowball stemming algorithms" and contains per-language folders such as `english`, `french`, `german`, `spanish`, `russian`, `turkish`, `polish`, etc.
- Snowball itself is explicitly built for text search stemming; the Snowball project notes that stem forms are intended for text search systems.

Also document Apache Lucene analysis/common as a broader reference, not a direct drop-in suite:

- Source: `https://lucene.apache.org/core/10_2_0/analysis/common/index.html`
- Lucene analysis/common contains analyzers for many languages and notes that CJKAnalyzer indexes bigrams.
- Do not claim we pass Lucene's Java unit tests unless you actually run/adapt them.

## Required Work

1. In the integrated codebase, add an optional external compliance harness for Snowball data.
2. The harness should:
   - Accept a local `SNOWBALL_DATA_DIR` path, so CI can provide a checkout without network access.
   - Avoid `curl`/`wget`.
   - Test only languages actually supported by the integrated PHP stemmer pipeline. It is acceptable to skip unsupported Snowball languages with a clear report.
   - Compare input vocabulary to expected output line-by-line for each language.
   - Fail loudly on mismatches for supported languages.
3. Add documentation explaining how to obtain the external suite, for example via `git clone https://github.com/snowballstem/snowball-data /tmp/snowball-data`, and how to run the harness.
4. If the integrated implementation cannot pass the full external suite yet:
   - Make the failing languages or cases explicit.
   - Decide whether this is due to unsupported language, dialect normalization before stemming, `wamania/php-stemmer` behavior, or our pipeline.
   - Fix actual implementation defects where reasonable.
5. Keep the normal no-WordPress suite passing.

## Verification

Run and report:

- `php /home/claude/indexer-integration/tests/run.php`
- External harness with a real Snowball data checkout if possible.
- `composer test` if available
- `find /home/claude/indexer-integration -path /home/claude/indexer-integration/vendor -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l`
- `git -C /home/claude/indexer-integration diff --check`

Report exactly which external languages passed, skipped, or failed.
