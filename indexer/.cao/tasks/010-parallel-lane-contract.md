# Parallel Lane Contract: Multilingual FTS Push

Project root: `/home/claude/indexer`
Baseline commit: `a6efcf2`
Updated spec: `/home/claude/indexer/goal.md`
Reviewer result to account for: `/home/claude/indexer/.cao/reviews/001-review-v1-result.md`

The updated goal adds first-class multilingual support. Six developers will work in isolated Git worktrees under `/home/claude/indexer-lanes`. Each lane should commit its own branch when done and report absolute paths plus test results.

## Shared Direction

Use the updated spec defaults unless a lane brief says otherwise:

- Terms are language-namespaced as `canonical_lang . "\x1e" . normalized_term`.
- Query defaults to one resolved language. Cross-language merging is out of scope unless a lane explicitly adds a safe opt-in.
- Analyzer content occurrences should carry `term`, `weight`, and `lang`.
- Query analysis should carry `term` and `lang`; when adapting old call sites, returning plain term strings may remain available as a compatibility shim only if tests cover it.
- Prefer canonical language keys such as `en`, `en-US`, `en-GB`, `pl`, `de`, `zh-Hans`, `zh-Hant`, `ja`, `ko`.
- Storage should be able to answer doc lengths and global stats per language. A practical contract is:
  - `get_doc_lengths(array $doc_ids, ?string $lang = null): array`
  - `get_doc(int $doc_id): ?array` with primary language, deleted state, content hash, and per-language lengths.
  - `put_doc(int $doc_id, string $primary_lang, array $lang_lengths, string $hash): void`
  - `get_meta(?string $lang = null): array{doc_count:int,len_sum:int}`
  - `add_meta(string $lang, int $d_docs, int $d_len): void`
- A document contributes to a language partition when it has at least one indexed token in that language.
- BM25 `N`, `avgdl`, and doc length must be taken from the query language partition.
- Keep indexing explicit. Do not add `save_post` or other automatic action hooks.

## Known Required Fixes From Review

These must be covered somewhere in the final merge:

- Guard or declare optional PHP extension use in `WP_FTS_Analyzer`; `iconv()` and `mb_convert_encoding()` cannot fatal unexpectedly.
- Do not HTML-decode `WP_HTML_Processor::get_modifiable_text()` output a second time; keep decoding in the fallback parser.

## Lane Boundaries

1. Analyzer core: language resolution, HTML `lang` extraction, script-aware tokenization, CJK n-grams, review fixes.
2. Stemmers/dialects: pluggable language pipelines, Snowball adapter, dialect/folding maps, Polish strategy stopgap/shape.
3. Language-aware storage: storage interface and in-memory/file implementations for per-language docs/meta.
4. Search/index stats: indexer/searcher behavior over language namespaced terms and per-language BM25.
5. MySQL/WP-CLI: schema, `$wpdb` implementation, table creation, CLI language options.
6. Tests/quality: T8 and regression tests, external BM25 reference harness if feasible, increased generated coverage.

Minimize cross-lane conflicts by preferring new small classes/helpers where useful. If you must touch files outside your lane, document exactly why in your report.

