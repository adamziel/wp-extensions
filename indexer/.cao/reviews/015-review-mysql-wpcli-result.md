Required fixes found for Lane 5 at commit `9cf35971b937f20b7359cc3b8e55f28c351dd7ab`.

Reviewed against:

- `/home/claude/indexer/goal.md`
- `/home/claude/indexer/.cao/tasks/010-parallel-lane-contract.md`
- `/home/claude/indexer/.cao/tasks/015-lane-mysql-wpcli.md`
- Lane 1 analyzer API: `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php`
- Lane 3 storage API result: `/home/claude/indexer/.cao/reviews/013-review-language-storage-result.md`
- Lane 4 search/index stats result: `/home/claude/indexer/.cao/reviews/014-review-search-stats-result.md`

Required fixes:

1. Pass the resolved document language into the analyzer during indexing.

   `/home/claude/indexer-lanes/mysql-wpcli/src/Indexer.php:23` resolves the requested primary language, and WP-CLI reindex forwards `--lang` into `index_document()` at `/home/claude/indexer-lanes/mysql-wpcli/src/Indexer.php:142`. However `/home/claude/indexer-lanes/mysql-wpcli/src/Indexer.php:30` calls `analyze_content($html)` without language options, then `/home/claude/indexer-lanes/mysql-wpcli/src/Indexer.php:232` lets any analyzer-supplied occurrence `lang` override the resolved primary language.

   This breaks the lane contract once Lane 1's analyzer is merged: Lane 1 emits language-tagged content occurrences from `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php:140` through `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php:149`, and it resolves document language from recognized options at `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php:695` through `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php:707`. Because Lane 5 does not pass those options, `index_document(1, ..., ['lang' => 'pl'])` can store `primary_lang => pl` while the actual term key is `en\x1e...` if the analyzer default is English.

   Required change: call the analyzer with the resolved document language using keys Lane 1 honors, for example `lang`, `language`, or `document_lang`, while preserving HTML element `lang` overrides in returned occurrences. Keep the current plain-occurrence fallback for the Lane 5 local analyzer.

2. Pass the resolved query language into query analysis, and request language-aware query occurrences when available.

   `/home/claude/indexer-lanes/mysql-wpcli/src/Searcher.php:20` resolves the query language, and WP-CLI search passes `--lang` to search at `/home/claude/indexer-lanes/mysql-wpcli/src/WPCLICommand.php:74` through `/home/claude/indexer-lanes/mysql-wpcli/src/WPCLICommand.php:78`. But `/home/claude/indexer-lanes/mysql-wpcli/src/Searcher.php:21` calls `analyze_query($query)` without language options. `/home/claude/indexer-lanes/mysql-wpcli/src/Searcher.php:118` through `/home/claude/indexer-lanes/mysql-wpcli/src/Searcher.php:137` can consume language-tagged query terms, but the call never requests them.

   Lane 1 only returns query occurrences when the caller requests that format at `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php:162` through `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php:166`; otherwise it returns plain strings at `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php:169` through `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php:172`. As a result, `wp fts search --lang=pl` can still analyze using the analyzer's default/query resolver language, which is wrong for language-specific tokenization, stopwords, folding, and stemming.

   Required change: pass `lang`/`query_lang` to query analysis and use `analyze_query_occurrences()` or `analyze_query(..., ['return' => 'occurrences'])` when available. Keep a plain-string fallback that namespaces terms with the explicit resolved query language.

Evidence reproduced during review:

- Loaded Lane 1's analyzer with Lane 5's indexer/storage. Indexing `'<p>zamek</p>'` with `['lang' => 'pl']` produced the term key `en\x1ezamek`, while the stored doc metadata was `primary_lang => pl` and `lang_lengths => ['en' => 1]`.
- Loaded a Polish term manually, then searched with `['lang' => 'pl']` using an analyzer whose English stopwords included `zamek`. Search returned `[]` because Lane 5 analyzed the query without the Polish language option before namespacing.

Review notes:

- MySQL schema shape matches the updated lane contract at static-review level: `fts_terms.term` is `varbinary(255)`, docs store primary `lang`, `fts_doc_lengths` provides per-language lengths, and `fts_meta` is keyed by `(lang,k)`: `/home/claude/indexer-lanes/mysql-wpcli/src/MysqlStorage.php:26`, `/home/claude/indexer-lanes/mysql-wpcli/src/MysqlStorage.php:32`, `/home/claude/indexer-lanes/mysql-wpcli/src/MysqlStorage.php:42`, `/home/claude/indexer-lanes/mysql-wpcli/src/MysqlStorage.php:49`.
- MySQL term writes use prepared `INSERT ... ON DUPLICATE KEY UPDATE` and enforce the 255-byte namespaced term limit: `/home/claude/indexer-lanes/mysql-wpcli/src/MysqlStorage.php:92` through `/home/claude/indexer-lanes/mysql-wpcli/src/MysqlStorage.php:111`.
- MySQL doc/meta methods use per-language doc lengths and per-language meta for search stats: `/home/claude/indexer-lanes/mysql-wpcli/src/MysqlStorage.php:122`, `/home/claude/indexer-lanes/mysql-wpcli/src/MysqlStorage.php:197`, `/home/claude/indexer-lanes/mysql-wpcli/src/MysqlStorage.php:244`, `/home/claude/indexer-lanes/mysql-wpcli/src/MysqlStorage.php:265`.
- No automatic `save_post` or similar indexing hook was added. The only plugin entrypoint registration is WP-CLI-only at `/home/claude/indexer-lanes/mysql-wpcli/indexer.php:13`.
- The fake `$wpdb` tests are meaningful for SQL call shape, prepared arguments, binary namespaced term separation, per-language doc lengths, and CLI filters. They still cannot validate live `dbDelta()`, real BLOB round trips, or InnoDB transaction behavior.

Likely merge conflicts:

- Lane 3 overlaps Lane 5 in `/home/claude/indexer-lanes/mysql-wpcli/src/StorageInterface.php`, `/home/claude/indexer-lanes/mysql-wpcli/src/InMemoryStorage.php`, `/home/claude/indexer-lanes/mysql-wpcli/src/FileStorage.php`, `/home/claude/indexer-lanes/mysql-wpcli/src/MysqlStorage.php`, and `/home/claude/indexer-lanes/mysql-wpcli/tests/run.php`.
- Lane 4 overlaps Lane 5 in `/home/claude/indexer-lanes/mysql-wpcli/src/Indexer.php`, `/home/claude/indexer-lanes/mysql-wpcli/src/Searcher.php`, `/home/claude/indexer-lanes/mysql-wpcli/src/bootstrap.php`, and `/home/claude/indexer-lanes/mysql-wpcli/tests/run.php`.
- Lane 5's `WP_FTS_Language` helper and Lane 4's `WP_FTS_TermNamespace` implement the same `lang . "\x1e" . term` concept with different default-language behavior (`und` vs `en`). Final integration should choose one canonical helper/default before merging search and MySQL paths.

Verification:

- `php /home/claude/indexer-lanes/mysql-wpcli/tests/run.php` -> `13/13 tests passed in 0.346s`
- `find /home/claude/indexer-lanes/mysql-wpcli -path /home/claude/indexer-lanes/mysql-wpcli/vendor -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l` -> no syntax errors
- `git -C /home/claude/indexer-lanes/mysql-wpcli diff --check a6efcf2..HEAD` -> no whitespace errors
- `node /home/claude/.codex/skills/wp-project-triage/scripts/detect_wp_project.mjs` from the lane root -> project kind reported `unknown`; reviewed as a standalone WordPress plugin-style package based on `indexer.php` and the WP-CLI command class.
