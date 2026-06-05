# Review Result: 023 Lane 2 Stemmers/Dialects Fix

Status: NOT APPROVED

Reviewed fix commit: `eb4250e981e69011b3ce3cfc36bf4a0e88d12827`

## Required Fixes

1. Preserve the old one-argument stemmer contract for callables with optional non-language parameters.

   References:
   - `/home/claude/indexer-lanes/stemmers-dialects/src/Stemmer.php:31`
   - `/home/claude/indexer-lanes/stemmers-dialects/src/Stemmer.php:33`
   - `/home/claude/indexer-lanes/stemmers-dialects/src/Stemmer.php:40`
   - `/home/claude/indexer-lanes/stemmers-dialects/src/Stemmer.php:42`
   - `/home/claude/indexer-lanes/stemmers-dialects/src/LanguagePipeline.php:99`
   - `/home/claude/indexer-lanes/stemmers-dialects/src/LanguagePipeline.php:100`
   - `/home/claude/indexer-lanes/stemmers-dialects/tests/run.php:270`

   The fix detects language-aware callables with `ReflectionFunction::getNumberOfParameters() >= 2`, which includes optional parameters. That still breaks existing valid one-argument callables whose second parameter is not a language. Baseline called custom stemmers with one argument at `/home/claude/indexer/src/Analyzer.php:363` and `/home/claude/indexer/src/Analyzer.php:364`.

   Reproducer on the fixed lane:

   ```text
   php -r 'require "/home/claude/indexer-lanes/stemmers-dialects/src/bootstrap.php"; $a = new WP_FTS_Analyzer(["stemmer" => "metaphone"]); var_export($a->analyze_query("testing"));'
   -> TypeError: metaphone(): Argument #2 ($max_phonemes) must be of type int, string given
   ```

   The same configuration on the baseline workspace returns `['TSTNK']`. `mb_strtolower` is another example: it has one required parameter and an optional encoding parameter, and the lane now passes language such as `en` as the encoding, raising `ValueError`.

   Required change: do not treat optional parameters as proof that the callback wants language. For the legacy `stemmer` option, dispatch with two arguments only for callables that require at least two parameters or are variadic, or add an explicit language-aware callback path while preserving one-argument dispatch for legacy callables. Add a regression using a one-argument-compatible internal callable with an optional non-language parameter, such as `metaphone`, in addition to the existing `strrev` regression.

## Confirmed Working

- Internal one-required-argument callable `strrev` now works.
- Two-required-argument custom stemmers receive the canonical language.
- Variadic custom stemmers receive the language.
- Direct source loading under `php -n` without `mb_strtolower` normalizes the reviewed uppercase fallback examples to `zolc`, `aerger`, `cig`, and `ecole`.
- Guarded `wamania/php-stemmer` behavior, deterministic language keys, namespaced terms, and the conservative Polish fallback positioning were not regressed by the changed files.

## Merge Notes

- Lane 1 analyzer-core still has direct conflicts with Lane 2 in `/home/claude/indexer-lanes/stemmers-dialects/src/Analyzer.php` and `/home/claude/indexer-lanes/stemmers-dialects/tests/run.php`. Final reconciliation should keep Lane 2's `WP_FTS_LanguagePipeline`, `WP_FTS_Normalizer`, `WP_FTS_Stemmer`, Snowball adapter, dialect maps, and Polish fallback, while also preserving Lane 1's array-option analyzer API, `analyze_query_occurrences()`, document/query language resolvers, HTML `lang` scope tracking, fallback optional-end handling, CJK/mixed-script tokenization, and optional-extension guards.
- Lane 4 search-stats currently calls analyzers with array options and prefers `analyze_query_occurrences()`. Lane 2 exposes `analyze_content(string, ?string)` / `analyze_query(string, ?string)` and `analyze_query_terms()`, so final integration must either add compatibility wrappers to the merged analyzer or adapt Lane 4's calls. Otherwise Lane 4's `analyze_content($html, $analysisOpts)` and `analyze_query($query, $returnOpts)` paths will be incompatible with Lane 2's signatures.
- Lane 4's `WP_FTS_TermNamespace` and Lane 2's `WP_FTS_LanguagePipeline::namespace_term()` both implement `lang . "\x1e" . term` with separate canonicalization/default-language behavior. Choose one canonical helper/default during final merge.

## Verification

- `php /home/claude/indexer-lanes/stemmers-dialects/tests/run.php` -> `16/16 tests passed in 0.411s`
- `php -n /home/claude/indexer-lanes/stemmers-dialects/tests/run.php` -> `16/16 tests passed in 0.487s`
- `composer test` -> `16/16 tests passed in 0.420s`
- `php -l /home/claude/indexer-lanes/stemmers-dialects/src/Normalizer.php` -> no syntax errors
- `php -l /home/claude/indexer-lanes/stemmers-dialects/src/Stemmer.php` -> no syntax errors
- `php -l /home/claude/indexer-lanes/stemmers-dialects/src/LanguagePipeline.php` -> no syntax errors
- `git -C /home/claude/indexer-lanes/stemmers-dialects diff --check` -> no output
- `git -C /home/claude/indexer-lanes/stemmers-dialects status --short --branch` -> `## lanes/stemmers-dialects`
- Targeted probes:
  - `strrev` custom stemmer -> `['ahpla']`
  - two-argument custom stemmer -> `['en-GB:color']`
  - variadic custom stemmer -> `['1:color']`
  - direct `php -n` normalizer source require -> `['zolc', 'aerger', 'cig', 'ecole']`
  - baseline `metaphone` custom stemmer -> `['TSTNK']`
  - fixed lane `metaphone` custom stemmer -> `TypeError`
