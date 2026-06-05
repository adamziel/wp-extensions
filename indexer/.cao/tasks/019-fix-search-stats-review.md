# Developer Fix Task: Lane 4 Search Stats Review Fixes

Worktree: `/home/claude/indexer-lanes/search-stats`
Branch: `lanes/search-stats`
Current lane commit: `8f1130f0b071ff4f3b79471e2f1ec8cbf3d722e6`
Review result: `/home/claude/indexer/.cao/reviews/014-review-search-stats-result.md`

Fix the two required reviewer findings for Lane 4. Do not work in other lane worktrees.

## Required Fixes

### 1. Preserve analyzer-selected query language

`WP_FTS_Searcher` currently calls `analyze_query()` without requesting language-aware occurrences, then falls back to the default language for plain string terms. Lane 1 keeps `analyze_query()` as a plain-term compatibility shim unless callers request occurrences, so query-language resolvers such as `query_lang => pl` can be lost during namespacing.

Required behavior:

- Prefer `analyze_query_occurrences()` when the analyzer exposes it.
- Otherwise request occurrence output via `analyze_query($query, ['return' => 'occurrences', ...])` or `['format' => 'occurrences']` if that is the available API.
- Preserve the existing plain-string fallback for older analyzers.
- Resolve `$queryLang` from returned occurrence `lang` values when available.
- Add a regression covering an analyzer configured for Polish query language where `search('lodz')` hits the Polish partition without requiring explicit `['lang' => 'pl']` on the search call.

### 2. Pass resolved document language using Lane 1-recognized keys

`WP_FTS_Indexer` resolves a primary document language but passes it to the analyzer only as `default_lang`. Lane 1 document language resolution does not read per-call `default_lang`; it reads `lang`, `language`, `document_lang`, `locale`, analyzer config/resolvers, WordPress language, and constructor default.

Required behavior:

- When the indexer resolves a primary language, pass it to `analyze_content()` as `document_lang` or another Lane 1-recognized key.
- Preserve explicit caller language options and HTML element `lang` overrides.
- Add a regression where `index_document(1, '<p>lodz</p>', ['default_lang' => 'pl'])` stores/queries the Polish partition consistently.

## Preserve

- Term namespace format: `canonical_lang . "\x1e" . term`.
- Single-language search partition by default.
- OR/AND semantics.
- Language-specific BM25 `N`, `avgdl`, doc lengths, and active doc frequency behavior.
- Fallback compatibility with older storage/analyzer APIs.

## Verification

Run and report:

- `php /home/claude/indexer-lanes/search-stats/tests/run.php`
- `composer test` if available
- `find /home/claude/indexer-lanes/search-stats/src -name '*.php' -print0 | xargs -0 -n1 php -l`
- `git -C /home/claude/indexer-lanes/search-stats diff --check`
- `git -C /home/claude/indexer-lanes/search-stats status --short --branch`

Commit the fix on `lanes/search-stats` and report the new commit SHA, changed absolute paths, commands/results, and remaining assumptions.
