Required fixes found for Lane 4 at commit `8f1130f0b071ff4f3b79471e2f1ec8cbf3d722e6`.

Reviewed against:

- `/home/claude/indexer/goal.md`
- `/home/claude/indexer/.cao/tasks/010-parallel-lane-contract.md`
- `/home/claude/indexer/.cao/tasks/014-lane-search-stats.md`
- Lane 3 review result: `/home/claude/indexer/.cao/reviews/013-review-language-storage-result.md`
- Lane 1 analyzer surface: `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php`

Required fixes:

1. Preserve analyzer-selected query language when querying.

   `/home/claude/indexer-lanes/search-stats/src/Searcher.php:112` calls `analyze_query()` without requesting language-aware occurrences, and `/home/claude/indexer-lanes/search-stats/src/Searcher.php:127` then falls back to `WP_FTS_TermNamespace::default_language()` when the analyzer returns plain strings. Lane 1 intentionally keeps `analyze_query()` as a plain-term compatibility shim unless callers request occurrences: `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php:162`, `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php:164`, `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php:166`, `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php:169`. As a result, an analyzer configured with `query_lang => pl` or a query-language resolver can tokenize under Polish but Lane 4 namespaces the query as the fallback language, commonly `en`, so the search misses the Polish partition.

   Required change: make `WP_FTS_Searcher::analyze_query()` request language-aware occurrences from the analyzer, for example by calling `analyze_query_occurrences()` when available or by setting `return`/`format` to `occurrences`, while keeping the existing string fallback for older analyzers. Then resolve `$queryLang` from those returned occurrence `lang` values.

2. Pass the resolved document language to the analyzer using keys Lane 1 honors.

   `/home/claude/indexer-lanes/search-stats/src/Indexer.php:183` resolves a primary language, but `/home/claude/indexer-lanes/search-stats/src/Indexer.php:204` only passes it as `default_lang` unless the caller supplied one of `lang`, `language`, `primary_lang`, or `document_lang` checked at `/home/claude/indexer-lanes/search-stats/src/Indexer.php:206`. Lane 1 document language resolution does not read per-call `default_lang`; it reads `lang`, `language`, `document_lang`, `locale`, analyzer configuration/resolvers, WordPress language, and finally the analyzer's constructor default: `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php:693`, `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php:695`, `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php:698`, `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php:700`, `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php:704`.

   This lets `index_document($id, $html, ['default_lang' => 'pl'])` record `primary_lang = pl` and a Polish content hash while the analyzer emits English terms and English `lang_lengths`. Required change: when Lane 4 resolves a primary document language, pass it to `analyze_content()` as `document_lang` or another Lane 1-recognized document language key, preserving explicit caller language options and HTML element `lang` overrides.

Review notes:

- Term namespace formatting uses `canonical_lang . "\x1e" . term`: `/home/claude/indexer-lanes/search-stats/src/TermNamespace.php:6`, `/home/claude/indexer-lanes/search-stats/src/TermNamespace.php:43`.
- Search scoring is single-partition by default: query terms are namespaced before lookup at `/home/claude/indexer-lanes/search-stats/src/Searcher.php:22`, doc lengths and meta are fetched with `$queryLang` at `/home/claude/indexer-lanes/search-stats/src/Searcher.php:59` and `/home/claude/indexer-lanes/search-stats/src/Searcher.php:64`, and active doc frequencies are filtered to docs with language-specific lengths at `/home/claude/indexer-lanes/search-stats/src/Searcher.php:71`.
- `WP_FTS_StorageCompat` matches Lane 3 in-memory/file arity and field names. Lane 3 MySQL deliberately throws for non-legacy language partitions until Lane 5 schema work, so final integration must merge Lane 5 MySQL before expecting language-aware MySQL indexing/search.
- Expected merge conflicts: `/home/claude/indexer-lanes/search-stats/src/Analyzer.php` and `/home/claude/indexer-lanes/search-stats/tests/run.php` with Lane 1; `/home/claude/indexer-lanes/search-stats/src/StorageInterface.php`, `/home/claude/indexer-lanes/search-stats/src/InMemoryStorage.php`, and `/home/claude/indexer-lanes/search-stats/src/FileStorage.php` with Lane 3.

Verification:

- `php /home/claude/indexer-lanes/search-stats/tests/run.php` -> `13/13 tests passed in 0.322s`
- `find /home/claude/indexer-lanes/search-stats/src -name '*.php' -print0 | xargs -0 -n1 php -l` -> no syntax errors
- `git -C /home/claude/indexer-lanes/search-stats diff --check a6efcf2..HEAD` -> no whitespace errors
- Reproduced the query-language bug by combining Lane 4 searcher/indexer with Lane 1 analyzer: with `WP_FTS_Analyzer(['default_lang' => 'en', 'query_lang' => 'pl', 'document_lang' => 'pl'])`, `search('lodz')` returned `[]`, while `search('lodz', ['lang' => 'pl'])` returned doc `1`.
- Reproduced the indexer language-propagation bug by combining Lane 4 indexer with Lane 1 analyzer and Lane 3 in-memory storage: `index_document(1, '<p>lodz</p>', ['default_lang' => 'pl'])` stored term `en\x1elodz` while the doc metadata recorded `primary_lang => pl`.
