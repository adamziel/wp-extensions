APPROVED

No required fixes found for Lane 4 search-stats fix at commit `e3145f973e657eba78dae90203d9ec30bf0430e8`.

Reviewed against:

- `/home/claude/indexer/goal.md`
- `/home/claude/indexer/.cao/tasks/010-parallel-lane-contract.md`
- `/home/claude/indexer/.cao/tasks/014-lane-search-stats.md`
- `/home/claude/indexer/.cao/reviews/014-review-search-stats-result.md`
- `/home/claude/indexer/.cao/tasks/019-fix-search-stats-review.md`
- Lane 1 analyzer surface at `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php`
- Lane 3 storage surface at `/home/claude/indexer-lanes/language-storage/src/StorageInterface.php`

Review notes:

- `WP_FTS_Searcher` now requests language-aware query occurrence output before falling back to compatibility modes: `/home/claude/indexer-lanes/search-stats/src/Searcher.php:110`. It prefers `analyze_query_occurrences()` when exposed, then tries `return => occurrences`, then `format => occurrences`, and only then accepts plain string terms: `/home/claude/indexer-lanes/search-stats/src/Searcher.php:114`, `/home/claude/indexer-lanes/search-stats/src/Searcher.php:120`, `/home/claude/indexer-lanes/search-stats/src/Searcher.php:127`, `/home/claude/indexer-lanes/search-stats/src/Searcher.php:134`.
- Analyzer-selected query language is preserved from returned occurrence rows when no explicit query language was passed: `/home/claude/indexer-lanes/search-stats/src/Searcher.php:175`, `/home/claude/indexer-lanes/search-stats/src/Searcher.php:182`, `/home/claude/indexer-lanes/search-stats/src/Searcher.php:183`. This matches Lane 1's query occurrence API: `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php:181`.
- Older/plain-string query analyzer output still gets namespaced with the explicit query language because `query_lang`, `lang`, and `language` are normalized into analysis options and resolved before fallback default language: `/home/claude/indexer-lanes/search-stats/src/Searcher.php:137`, `/home/claude/indexer-lanes/search-stats/src/Searcher.php:140`, `/home/claude/indexer-lanes/search-stats/src/Searcher.php:177`, `/home/claude/indexer-lanes/search-stats/src/Searcher.php:194`, `/home/claude/indexer-lanes/search-stats/src/Searcher.php:201`.
- `WP_FTS_Indexer` now passes the resolved primary language as `document_lang`, which Lane 1 recognizes for document language resolution: `/home/claude/indexer-lanes/search-stats/src/Indexer.php:183`, `/home/claude/indexer-lanes/search-stats/src/Indexer.php:202`, `/home/claude/indexer-lanes/search-stats/src/Indexer.php:205`, `/home/claude/indexer-lanes/search-stats/src/Indexer.php:206`, `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php:695`, `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php:700`.
- Explicit caller language options remain authoritative for the resolved primary language, and analyzer occurrence `lang` values continue to drive term namespaces and per-language lengths, so Lane 1 HTML element `lang` overrides are preserved when merged: `/home/claude/indexer-lanes/search-stats/src/Indexer.php:187`, `/home/claude/indexer-lanes/search-stats/src/Indexer.php:207`, `/home/claude/indexer-lanes/search-stats/src/Indexer.php:230`, `/home/claude/indexer-lanes/search-stats/src/Indexer.php:231`, `/home/claude/indexer-lanes/search-stats/src/Indexer.php:244`, `/home/claude/indexer-lanes/search-stats/src/Indexer.php:249`.
- Language-specific BM25 inputs are still selected from the query language partition: namespaced term lookup at `/home/claude/indexer-lanes/search-stats/src/Searcher.php:22`, language-specific doc lengths at `/home/claude/indexer-lanes/search-stats/src/Searcher.php:59`, language-specific meta at `/home/claude/indexer-lanes/search-stats/src/Searcher.php:64`, and active doc frequency filtering at `/home/claude/indexer-lanes/search-stats/src/Searcher.php:71`.
- OR/AND behavior and namespace filtering remain intact: mode validation and missing-term AND handling at `/home/claude/indexer-lanes/search-stats/src/Searcher.php:27`, `/home/claude/indexer-lanes/search-stats/src/Searcher.php:34`, per-document AND filtering at `/home/claude/indexer-lanes/search-stats/src/Searcher.php:78`, and mixed-language query occurrence filtering at `/home/claude/indexer-lanes/search-stats/src/Searcher.php:214`, `/home/claude/indexer-lanes/search-stats/src/Searcher.php:223`.
- Regressions cover the two prior required fixes: analyzer-selected Polish query language at `/home/claude/indexer-lanes/search-stats/tests/run.php:563`, and `default_lang` reaching the analyzer as `document_lang` at `/home/claude/indexer-lanes/search-stats/tests/run.php:578`.

Non-blocking merge notes:

- Merge Lane 1's analyzer as the authoritative analyzer implementation. Lane 4's search/index changes integrate with Lane 1's `analyze_query_occurrences()` and `document_lang` APIs, while Lane 1 owns HTML `lang` extraction, script-aware tokenization, optional extension guards, and the HTML text decoding fixes.
- Merge Lane 3's storage interface/backends before relying on production per-language doc lengths/meta outside the test adapter. Lane 4's compatibility helper expects the Lane 3 method shapes: `/home/claude/indexer-lanes/language-storage/src/StorageInterface.php:20`, `/home/claude/indexer-lanes/language-storage/src/StorageInterface.php:35`, `/home/claude/indexer-lanes/language-storage/src/StorageInterface.php:42`, `/home/claude/indexer-lanes/language-storage/src/StorageInterface.php:48`.
- Lane 5 MySQL/schema work is still needed before language-aware MySQL storage can be expected end to end; Lane 4 preserves the search/index behavior over the storage contract but does not own that schema migration.

Verification:

- `php /home/claude/indexer-lanes/search-stats/tests/run.php` -> `15/15 tests passed in 0.492s`
- `composer test` from `/home/claude/indexer-lanes/search-stats` -> `15/15 tests passed in 0.325s`
- `find /home/claude/indexer-lanes/search-stats/src -name '*.php' -print0 | xargs -0 -n1 php -l` -> no syntax errors
- `git -C /home/claude/indexer-lanes/search-stats diff --check` -> clean
- `git -C /home/claude/indexer-lanes/search-stats status --short --branch` -> `## lanes/search-stats`
