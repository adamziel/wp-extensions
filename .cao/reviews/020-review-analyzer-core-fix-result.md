# Review Result: 020 Lane 1 Analyzer Core Fix

Status: APPROVED

Required fixes: None.

## Findings

No required fixes found for the Lane 1 analyzer language-scope fix.

The prior leak is resolved in both reviewed paths:

- Processor path: `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php:292` prunes deeper scopes, `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php:302` clears same-depth stale element scopes before applying a new opener's `lang`, and `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php:323` emits the restored current language.
- Fallback parser path: `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php:400` closes optional-end siblings before pushing the new tag, and `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php:931` through `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php:988` covers the requested paragraph/list/table optional-end families.

Previously accepted analyzer behavior remains intact:

- Processor extraction still only reads `#text` tokens before calling `get_modifiable_text()` at `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php:310` and `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php:318`.
- Processor text is not entity-decoded a second time; fallback parser decoding remains isolated at `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php:419`.
- Optional extension calls remain guarded around `transliterator_transliterate()`, `iconv()`, `mb_convert_encoding()`, `mb_strtolower()`, and `mb_strlen()`.
- Mixed-script and CJK tokenization remains covered by `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php:467` through `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php:499` and `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php:1023` through `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php:1083`.

## Verification

- `php /home/claude/indexer-lanes/analyzer-core/tests/run.php` -> `16/16 tests passed in 0.370s`
- `php -n /home/claude/indexer-lanes/analyzer-core/tests/run.php` -> `16/16 tests passed in 0.394s`
- `php -l /home/claude/indexer-lanes/analyzer-core/src/Analyzer.php` -> no syntax errors
- `git -C /home/claude/indexer-lanes/analyzer-core diff --check` -> no output
- `git -C /home/claude/indexer-lanes/analyzer-core status --short --branch` -> `## lanes/analyzer-core`

Targeted probes:

- `<p lang=pl>Łódź<p>Hello` -> `lodz:pl`, `hello:en`
- `<p lang=pl>Łódź</p><p>Hello</p>` -> `lodz:pl`, `hello:en`
- `<section lang=pl>Łódź <span lang=en>Hello</span> Wrocław</section>` -> `lodz:pl`, `hello:en`, `wroclaw:pl`
- `<ul><li lang=pl>Łódź<li>Hello</ul>` -> `lodz:pl`, `hello:en`
- `<table><tr><td lang=pl>Łódź<td>Hello</table>` -> `lodz:pl`, `hello:en`
- `<table><tr lang=pl><td>Łódź<tr><td>Hello</table>` -> `lodz:pl`, `hello:en`

## Merge Notes

- Lane 2 (`lanes/stemmers-dialects`) has direct merge conflicts in `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php` and `/home/claude/indexer-lanes/analyzer-core/tests/run.php`. During reconciliation, port this lane's same-depth processor-scope clearing and fallback optional-end handling into the analyzer that adopts Lane 2's `WP_FTS_LanguagePipeline`, `WP_FTS_Normalizer`, and stemmer classes.
- Lane 4 (`lanes/search-stats`) depends on the array-option analyzer API and language-tagged analyzer output. If Lane 2's alternate `?string $language` signatures are merged, preserve wrappers or adapt Lane 4 so `analyze_content($html, $options)` and `analyze_query($query, $options)` continue to work with `lang`-tagged occurrences.
