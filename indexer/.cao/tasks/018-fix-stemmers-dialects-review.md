# Developer Fix Task: Lane 2 Stemmers/Dialects Review Fixes

Worktree: `/home/claude/indexer-lanes/stemmers-dialects`
Branch: `lanes/stemmers-dialects`
Current lane commit: `476ded5eb2eb90e522c5522c93503cb2a37b293d`
Review result: `/home/claude/indexer/.cao/reviews/012-review-stemmers-dialects-result.md`

Fix the two required reviewer findings for Lane 2. Do not work in other lane worktrees.

## Required Fixes

### 1. Preserve one-argument custom stemmer compatibility

Before this lane, `WP_FTS_Analyzer(['stemmer' => ...])` called custom stemmers with one argument. `WP_FTS_CallbackStemmer` now always calls callbacks with `($term, $language)`, which fatals for internal one-argument callables such as `strrev`.

Required behavior:

- Existing one-argument callables, including internal callables like `strrev`, must still work.
- Language-aware two-argument callables must still be supported.
- Add a regression test using an internal one-argument callable.

Suggested approaches:

- Reflect the callable arity and dispatch one or two arguments accordingly.
- Or expose/document a separate explicit language-aware callable path while preserving one-argument behavior for `stemmer`.

### 2. Complete no-vendor/no-mbstring uppercase non-ASCII folding

The current missing-vendor/no-extension path falls back to ASCII `strtolower()`, and manual fold maps only cover lowercase non-ASCII entries. Under direct source require with `php -n`, uppercase words such as `ŻÓŁĆ`, `ÄRGER`, `ÇİĞ`, and `ÉCOLE` are incompletely normalized.

Required behavior:

- Folding/lowercase fallback for Polish, German, Turkish, and Latin fallback must handle uppercase non-ASCII without `mbstring`, `iconv`, `intl`, or Composer polyfills.
- Add uppercase map entries or an internal UTF-8 lower/fold table.
- Add regression coverage that exercises this path directly enough to fail without the fix.

## Preserve

- Existing tests must continue passing.
- Keep guarded `wamania/php-stemmer` behavior.
- Keep deterministic language keys and namespaced term behavior.
- Do not claim the Polish fallback is equivalent to Stempel/Morfologik.

## Verification

Run and report:

- `php /home/claude/indexer-lanes/stemmers-dialects/tests/run.php`
- `composer test` if available
- `php -n /home/claude/indexer-lanes/stemmers-dialects/tests/run.php`
- `php -l /home/claude/indexer-lanes/stemmers-dialects/src/Normalizer.php`
- `php -l /home/claude/indexer-lanes/stemmers-dialects/src/Stemmer.php`
- `php -l /home/claude/indexer-lanes/stemmers-dialects/src/LanguagePipeline.php`
- `git -C /home/claude/indexer-lanes/stemmers-dialects diff --check`
- `git -C /home/claude/indexer-lanes/stemmers-dialects status --short --branch`

Commit the fix on `lanes/stemmers-dialects` and report the new commit SHA, changed absolute paths, commands/results, and remaining assumptions.
