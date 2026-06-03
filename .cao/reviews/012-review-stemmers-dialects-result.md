# Review Result: 012 Lane 2 Stemmers and Dialects

Status: NOT APPROVED

## Required Fixes

1. Preserve the existing one-argument custom stemmer callable contract.

   References:
   - `/home/claude/indexer-lanes/stemmers-dialects/src/Stemmer.php:27`
   - `/home/claude/indexer-lanes/stemmers-dialects/src/Stemmer.php:29`
   - `/home/claude/indexer-lanes/stemmers-dialects/src/LanguagePipeline.php:20`
   - `/home/claude/indexer-lanes/stemmers-dialects/src/LanguagePipeline.php:99`

   Before this lane, `WP_FTS_Analyzer(['stemmer' => ...])` called the custom stemmer with one argument. The new `WP_FTS_CallbackStemmer` always calls the callback with `($term, $language)`. Userland closures that declare one parameter tolerate the extra argument, but internal callables do not. For example, an existing valid configuration such as `['stemmer' => 'strrev']` now fatals with `ArgumentCountError: strrev() expects exactly 1 argument, 2 given` from `Stemmer.php:29`.

   Required fix: keep language-aware custom stemmers supported, but preserve one-argument compatibility. For example, adapt by reflection/arity or by documenting and adding a separate two-argument option, then add a regression test using an internal one-argument callable.

2. Make the no-vendor/no-`mbstring` folding fallback complete for uppercase non-ASCII input.

   References:
   - `/home/claude/indexer-lanes/stemmers-dialects/src/Normalizer.php:89`
   - `/home/claude/indexer-lanes/stemmers-dialects/src/Normalizer.php:91`
   - `/home/claude/indexer-lanes/stemmers-dialects/src/Normalizer.php:169`
   - `/home/claude/indexer-lanes/stemmers-dialects/src/Normalizer.php:187`
   - `/home/claude/indexer-lanes/stemmers-dialects/src/Normalizer.php:200`
   - `/home/claude/indexer-lanes/stemmers-dialects/src/Normalizer.php:214`

   The normal test path passes because `vendor/autoload.php` is present and Composer supplies an `mb_strtolower()` polyfill. In the explicitly guarded "vendor files not installed yet" path, the source falls back to ASCII `strtolower()` and the manual fold maps contain only lowercase non-ASCII entries. Simulating that path with direct source requires under `php -n` leaves uppercase diacritics unnormalized: `ŻÓŁĆ` in `pl` stays `ŻÓŁĆ`, `ÄRGER` in `de` stays `Ärger`, `ÇİĞ` in `tr` stays `ÇiĞ`, and `ÉCOLE` in the Latin fallback stays `École`. That violates the lane requirement for deterministic per-language folding without optional extension/vendor availability.

   Required fix: either add uppercase variants to the Polish, German, Turkish, and Latin fallback maps or implement an internal UTF-8 lower/fold table that does not depend on `mbstring`, `iconv`, `intl`, or Composer polyfills. Add a regression that exercises the missing-vendor/no-extension path or otherwise covers uppercase map entries directly.

## Merge Notes

- Lane 1 analyzer-core will conflict in `/home/claude/indexer-lanes/stemmers-dialects/src/Analyzer.php` and `/home/claude/indexer-lanes/stemmers-dialects/tests/run.php`. The main reconciliation point is API shape: Lane 2 accepts `?string $language` and exposes `analyze_query_terms()`, while Lane 1 uses array options, language resolvers, `lang` attribute tracking, CJK tokenization, and `analyze_query_occurrences()`.
- Lane 4 search-stats will conflict in `/home/claude/indexer-lanes/stemmers-dialects/src/bootstrap.php` because both branches add new source includes. Lane 4 also expects analyzer calls that accept array language options and emits/consumes language-tagged occurrences for namespacing, so the final merge must bridge Lane 2's pipeline into that contract.

## Verification

- `php /home/claude/indexer-lanes/stemmers-dialects/tests/run.php`: 14/14 tests passed.
- `composer test`: 14/14 tests passed.
- `php -n /home/claude/indexer-lanes/stemmers-dialects/tests/run.php`: 14/14 tests passed with installed vendor polyfills.
- `php -l` on `src/Analyzer.php`, `src/Normalizer.php`, `src/Stemmer.php`, and `src/LanguagePipeline.php`: no syntax errors.
- Targeted compatibility check for `['stemmer' => 'strrev']`: fataled at `/home/claude/indexer-lanes/stemmers-dialects/src/Stemmer.php:29`.
- Targeted no-vendor/no-`mbstring` normalization check by requiring `src/Normalizer.php` directly under `php -n`: uppercase non-ASCII folding failed as described above.
