# Review Result: 027 Lane 2 Stemmer Optional-Arity Fix

Status: APPROVED

Reviewed lane: `lanes/stemmers-dialects`
Reviewed worktree: `/home/claude/indexer-lanes/stemmers-dialects`
Reviewed commit: `d0021b4b6ac130fa479145244968ff86dbeee055`

## Required Fixes

None.

## Review Notes

- Legacy one-argument stemmer dispatch is preserved. `/home/claude/indexer-lanes/stemmers-dialects/src/Stemmer.php:31` and `/home/claude/indexer-lanes/stemmers-dialects/src/Stemmer.php:34` still call legacy stemmers with one argument when the callback is not classified as language-aware.
- Optional non-language parameters are no longer treated as language-aware. `/home/claude/indexer-lanes/stemmers-dialects/src/Stemmer.php:40` reflects the callable and `/home/claude/indexer-lanes/stemmers-dialects/src/Stemmer.php:44` now requires either variadic support or at least two required parameters before passing the language string.
- The regression coverage matches the requested callable shapes: `strrev` at `/home/claude/indexer-lanes/stemmers-dialects/tests/run.php:271`, `metaphone` at `/home/claude/indexer-lanes/stemmers-dialects/tests/run.php:276`, two-required-argument custom stemmers at `/home/claude/indexer-lanes/stemmers-dialects/tests/run.php:281`, and variadic stemmers at `/home/claude/indexer-lanes/stemmers-dialects/tests/run.php:286`.
- The previous uppercase non-ASCII fallback coverage remains in place at `/home/claude/indexer-lanes/stemmers-dialects/tests/run.php:250`, with implementation coverage through `/home/claude/indexer-lanes/stemmers-dialects/src/Normalizer.php:82`, `/home/claude/indexer-lanes/stemmers-dialects/src/Normalizer.php:90`, and `/home/claude/indexer-lanes/stemmers-dialects/src/Normalizer.php:105`.

## Targeted Probes

- `WP_FTS_Analyzer(["stemmer" => "metaphone"])->analyze_query("testing")` returned `["TSTNK"]`.
- `WP_FTS_Analyzer(["stemmer" => "mb_strtolower"])->analyze_query("TESTING")` returned `["testing"]`, confirming the language string is not passed as the optional encoding argument.
- A two-required-argument callback returned `["en-GB:color"]`.
- A variadic callback returned `["1:color"]`.

## Merge Notes

- Lane 1 analyzer-core still uses array-option analyzer signatures and exposes `analyze_query_occurrences()` at `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php:140`, `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php:162`, and `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php:181`. Lane 2 currently exposes `analyze_content(string, ?string)` and `analyze_query(string, ?string)` at `/home/claude/indexer-lanes/stemmers-dialects/src/Analyzer.php:97` and `/home/claude/indexer-lanes/stemmers-dialects/src/Analyzer.php:135`, plus `analyze_query_terms()` at `/home/claude/indexer-lanes/stemmers-dialects/src/Analyzer.php:148`. The final merge should preserve Lane 1's array-option and occurrence-return compatibility while keeping Lane 2's language pipeline behavior.
- Lane 4 search-stats calls analyzers with option arrays and prefers `analyze_query_occurrences()` at `/home/claude/indexer-lanes/search-stats/src/Indexer.php:212`, `/home/claude/indexer-lanes/search-stats/src/Searcher.php:114`, and `/home/claude/indexer-lanes/search-stats/src/Searcher.php:122`. The final integration needs compatibility wrappers or adapted calls.
- Lane 4 has `WP_FTS_TermNamespace::namespace_term()` at `/home/claude/indexer-lanes/search-stats/src/TermNamespace.php:43`; Lane 2 has `WP_FTS_LanguagePipeline::namespace_term()` at `/home/claude/indexer-lanes/stemmers-dialects/src/LanguagePipeline.php:89`. The merge should choose one canonical namespace helper/default-language policy.

## Verification

- `php /home/claude/indexer-lanes/stemmers-dialects/tests/run.php` -> `16/16 tests passed in 0.460s`
- `composer test` -> `16/16 tests passed in 0.407s`
- `php -n /home/claude/indexer-lanes/stemmers-dialects/tests/run.php` -> `16/16 tests passed in 0.561s`
- `php -l /home/claude/indexer-lanes/stemmers-dialects/src/Stemmer.php` -> no syntax errors
- `git -C /home/claude/indexer-lanes/stemmers-dialects diff --check` -> clean
- `git -C /home/claude/indexer-lanes/stemmers-dialects status --short --branch` -> `## lanes/stemmers-dialects`
