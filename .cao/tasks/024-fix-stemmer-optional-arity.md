# Developer Fix Task: Lane 2 Optional-Parameter Stemmer Compatibility

Worktree: `/home/claude/indexer-lanes/stemmers-dialects`
Branch: `lanes/stemmers-dialects`
Current lane commit: `eb4250e981e69011b3ce3cfc36bf4a0e88d12827`
Review result: `/home/claude/indexer/.cao/reviews/023-review-stemmers-dialects-fix-result.md`

Fix the remaining required reviewer finding for Lane 2. Do not work in other lane worktrees.

## Required Fix

The previous fix treats any callable with two or more parameters as language-aware. That includes legacy one-argument-compatible internal callables whose second parameter is optional and not a language, such as:

- `metaphone(string $string, int $max_phonemes = 0)`
- `mb_strtolower(string $string, ?string $encoding = null)`

Before this lane, `WP_FTS_Analyzer(['stemmer' => ...])` called custom stemmers with one argument. Preserve that legacy behavior for callables that only require one argument.

Required behavior:

- For the legacy `stemmer` option, call with two arguments only when the callable requires at least two parameters or is variadic.
- Callables with one required parameter and optional extra parameters must be called with one argument.
- Keep two-required-argument and variadic language-aware custom stemmers working.
- Add a regression using `metaphone` in addition to the existing `strrev` regression.

## Preserve

- Existing 16 tests and the no-mbstring uppercase folding fix.
- Guarded `wamania/php-stemmer` behavior.
- Deterministic language keys and namespaced term behavior.
- Conservative Polish fallback positioning.

## Verification

Run and report:

- `php /home/claude/indexer-lanes/stemmers-dialects/tests/run.php`
- `composer test` if available
- `php -n /home/claude/indexer-lanes/stemmers-dialects/tests/run.php`
- `php -l /home/claude/indexer-lanes/stemmers-dialects/src/Stemmer.php`
- `git -C /home/claude/indexer-lanes/stemmers-dialects diff --check`
- `git -C /home/claude/indexer-lanes/stemmers-dialects status --short --branch`

Commit the fix on `lanes/stemmers-dialects` and report the new commit SHA, changed absolute paths, commands/results, and remaining assumptions.
