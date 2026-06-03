APPROVED

Reviewed Lane 5 MySQL/WP-CLI fix commit `9fdb2e5d67b169a1b7475bf6fdb18eda2df352cd` in `/home/claude/indexer-lanes/mysql-wpcli` against `/home/claude/indexer/.cao/reviews/028-review-mysql-wpcli-fix.md`.

No required fixes remain.

Review notes:

- `/home/claude/indexer-lanes/mysql-wpcli/src/Indexer.php:23` resolves the document language, `/home/claude/indexer-lanes/mysql-wpcli/src/Indexer.php:30` passes analysis through the new helper, and `/home/claude/indexer-lanes/mysql-wpcli/src/Indexer.php:229` through `/home/claude/indexer-lanes/mysql-wpcli/src/Indexer.php:233` send the Lane 1-recognized `lang`, `language`, and `document_lang` keys.
- `/home/claude/indexer-lanes/mysql-wpcli/src/Indexer.php:244` through `/home/claude/indexer-lanes/mysql-wpcli/src/Indexer.php:260` preserve analyzer-supplied occurrence languages for HTML element `lang` overrides while retaining the plain-string fallback under the resolved primary language.
- `/home/claude/indexer-lanes/mysql-wpcli/src/Searcher.php:20` through `/home/claude/indexer-lanes/mysql-wpcli/src/Searcher.php:21` use the resolved query language, and `/home/claude/indexer-lanes/mysql-wpcli/src/Searcher.php:117` through `/home/claude/indexer-lanes/mysql-wpcli/src/Searcher.php:134` prefer `analyze_query_occurrences()` while passing `lang`, `language`, `query_lang`, and `return => occurrences`.
- `/home/claude/indexer-lanes/mysql-wpcli/src/Searcher.php:145` through `/home/claude/indexer-lanes/mysql-wpcli/src/Searcher.php:158` still namespaces plain-string query fallback terms with the explicit resolved query language.
- WP-CLI language flow is intact: reindex accepts `--lang`/`--language` at `/home/claude/indexer-lanes/mysql-wpcli/src/WPCLICommand.php:35` through `/home/claude/indexer-lanes/mysql-wpcli/src/WPCLICommand.php:46`, and search forwards language at `/home/claude/indexer-lanes/mysql-wpcli/src/WPCLICommand.php:74` through `/home/claude/indexer-lanes/mysql-wpcli/src/WPCLICommand.php:78`.
- MySQL schema/storage behavior remains unchanged by the fix and still matches the lane contract at static-review level: binary term keys at `/home/claude/indexer-lanes/mysql-wpcli/src/MysqlStorage.php:26` through `/home/claude/indexer-lanes/mysql-wpcli/src/MysqlStorage.php:31`, per-language doc lengths at `/home/claude/indexer-lanes/mysql-wpcli/src/MysqlStorage.php:42` through `/home/claude/indexer-lanes/mysql-wpcli/src/MysqlStorage.php:48`, language-keyed meta at `/home/claude/indexer-lanes/mysql-wpcli/src/MysqlStorage.php:49` through `/home/claude/indexer-lanes/mysql-wpcli/src/MysqlStorage.php:54`, exact namespaced term writes at `/home/claude/indexer-lanes/mysql-wpcli/src/MysqlStorage.php:92` through `/home/claude/indexer-lanes/mysql-wpcli/src/MysqlStorage.php:111`, language doc-length reads at `/home/claude/indexer-lanes/mysql-wpcli/src/MysqlStorage.php:122` through `/home/claude/indexer-lanes/mysql-wpcli/src/MysqlStorage.php:156`, and per-language meta at `/home/claude/indexer-lanes/mysql-wpcli/src/MysqlStorage.php:244` through `/home/claude/indexer-lanes/mysql-wpcli/src/MysqlStorage.php:279`.
- No automatic hooks were added; plugin registration remains WP-CLI-only at `/home/claude/indexer-lanes/mysql-wpcli/indexer.php:13` through `/home/claude/indexer-lanes/mysql-wpcli/indexer.php:14`.
- Regression coverage was added at `/home/claude/indexer-lanes/mysql-wpcli/tests/run.php:919` through `/home/claude/indexer-lanes/mysql-wpcli/tests/run.php:980` for document/query language option passing, occurrence preference, fallback query occurrence requests, Polish query analysis, and occurrence-level language preservation.

Cross-lane check:

- Loaded Lane 1 analyzer from `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php` with Lane 5 `Language`, `InMemoryStorage`, `Indexer`, and `Searcher`. Indexing `'<p>zamek</p><code lang="en">castle</code>'` with `['lang' => 'pl']` stored `en\x1ecastle` and `pl\x1ezamek`, document `primary_lang => pl`, `lang_lengths => ['en' => 1, 'pl' => 1]`, returned doc `1` for `search('zamek', ['lang' => 'pl'])`, and returned no result for `search('zamek', ['lang' => 'en'])`.

Remaining merge notes, not required Lane 5 fixes:

- Live MySQL/dbDelta behavior remains syntax/fake-`$wpdb` reviewed only in this environment; no live database integration test validated `dbDelta()`, varbinary/blob round trips, or transaction behavior.
- Final merge should reconcile Lane 5's `WP_FTS_Language` helper/default with Lane 3/4 language namespace helpers and defaults.

Verification:

- `php /home/claude/indexer-lanes/mysql-wpcli/tests/run.php` -> `16/16 tests passed in 0.313s`
- `composer test` from `/home/claude/indexer-lanes/mysql-wpcli` -> `16/16 tests passed in 0.315s`
- `find /home/claude/indexer-lanes/mysql-wpcli -path /home/claude/indexer-lanes/mysql-wpcli/vendor -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l` -> no syntax errors
- `git -C /home/claude/indexer-lanes/mysql-wpcli diff --check` -> clean
- `git -C /home/claude/indexer-lanes/mysql-wpcli status --short --branch` -> `## lanes/mysql-wpcli`
