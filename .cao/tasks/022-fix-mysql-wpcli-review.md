# Developer Fix Task: Lane 5 MySQL/WP-CLI Review Fixes

Worktree: `/home/claude/indexer-lanes/mysql-wpcli`
Branch: `lanes/mysql-wpcli`
Current lane commit: `9cf35971b937f20b7359cc3b8e55f28c351dd7ab`
Review result: `/home/claude/indexer/.cao/reviews/015-review-mysql-wpcli-result.md`

Fix the two required reviewer findings for Lane 5. Do not work in other lane worktrees.

## Required Fixes

### 1. Pass resolved document language into analyzer during indexing

`WP_FTS_Indexer` resolves the primary language and WP-CLI forwards `--lang` into `index_document()`, but the indexer calls `analyze_content($html)` with no language options. With Lane 1's analyzer, that can store `primary_lang => pl` while actual terms are emitted as `en\x1e...` when the analyzer default is English.

Required behavior:

- Call the analyzer with the resolved document language using keys Lane 1 honors, such as `lang`, `language`, or `document_lang`.
- Preserve HTML element `lang` overrides in returned occurrences.
- Keep the current plain-occurrence fallback for older/local analyzers.
- Add a regression where indexing `'<p>zamek</p>'` with `['lang' => 'pl']` stores Polish namespaced terms and Polish doc metadata consistently.

### 2. Pass resolved query language and request language-aware query occurrences

`WP_FTS_Searcher` resolves the query language and WP-CLI passes `--lang`, but query analysis is called without language options and without requesting occurrence output. Lane 1 only returns language-tagged query terms when requested.

Required behavior:

- Pass `lang`/`query_lang` into query analysis.
- Prefer `analyze_query_occurrences()` when available.
- Otherwise call `analyze_query($query, ['lang' => $queryLang, 'query_lang' => $queryLang, 'return' => 'occurrences'])` or equivalent.
- Keep a plain-string fallback that namespaces terms with the explicit resolved query language.
- Add a regression where `search(..., ['lang' => 'pl'])` analyzes under Polish even when the analyzer's English pipeline would remove or alter the term.

## Preserve

- MySQL schema shape and fake `$wpdb` test coverage.
- Exact binary namespaced term behavior.
- WP-CLI `--lang` / `--language` behavior and no automatic hooks.
- Existing local tests.

## Verification

Run and report:

- `php /home/claude/indexer-lanes/mysql-wpcli/tests/run.php`
- `composer test` if available
- `find /home/claude/indexer-lanes/mysql-wpcli -path /home/claude/indexer-lanes/mysql-wpcli/vendor -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l`
- `git -C /home/claude/indexer-lanes/mysql-wpcli diff --check`
- `git -C /home/claude/indexer-lanes/mysql-wpcli status --short --branch`

Commit the fix on `lanes/mysql-wpcli` and report the new commit SHA, changed absolute paths, commands/results, and remaining assumptions.
