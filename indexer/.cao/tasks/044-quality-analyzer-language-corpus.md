# Task 044: Add Analyzer and Language Corpus Quality Tests

## Context

Read the shared contract first:

```text
/home/claude/indexer/.cao/tasks/042-quality-expansion-contract.md
```

Work from current trunk commit:

```text
581c3a4e8893c48f28d341d6f4e86deb7693420a
```

## Required Work

Create a `quality/analyzer-language-corpus` branch/worktree and add high-diversity analyzer tests, preferably in:

```text
tests/quality/analyzer-language-corpus.php
```

Target at least 500 meaningful executed checks from this lane.

Cover:

- HTML `lang` and `xml:lang` inheritance, explicit close, omitted close, nested overrides, sibling restoration, and mixed block/list/table contexts.
- Unsafe-region exclusion with language attributes inside script/style/template/svg and comments.
- WordPress processor path parity with fallback extraction where feasible.
- Mixed Latin/CJK text, CJK bigrams, min-length bypass for CJK, punctuation boundaries, apostrophes, numbers, emoji, combining marks, invalid UTF-8 recovery.
- Language/dialect canonicalization for `en-US`, `en-GB`, `pl-PL`, `de-DE`, `tr-TR`, `zh-Hans`, `zh-Hant`, region/script case normalization, and malformed language fallback.
- Language-specific folding for Polish, German, Turkish dotted/dotless I, Latin fallback accents, and uppercase no-mbstring paths.
- Query analysis parity and occurrence output: `analyze_query_occurrences()`, `return => occurrences`, plain-term compatibility.
- Custom stemmer arity compatibility with internal, one-arg, two-required-arg, and variadic callables.

Do not add shallow duplicates. Use data tables and generated combinations where each dimension changes behavior or guards an invariant.

## Acceptance

Run and report, using the lane's available harness integration:

```bash
php tests/run.php
composer test
php -n tests/run.php
find . -path ./vendor -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
git diff --check
git status --short --branch
```

Commit the result and send the commit SHA plus approximate executed check contribution back to terminal `da2963f2`.
