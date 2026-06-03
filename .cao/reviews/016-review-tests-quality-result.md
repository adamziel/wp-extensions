# Review Result: 016 Tests/Quality Lane

Status: NOT APPROVED

## Required Fixes

1. The T8 query analyzer fixture can remain pending after the language-aware analyzer lands.

   References:
   - `/home/claude/indexer-lanes/tests-quality/tests/run.php:180`
   - `/home/claude/indexer-lanes/tests-quality/tests/run.php:688`
   - `/home/claude/indexer-lanes/tests-quality/tests/run.php:690`

   The helper calls `analyze_query()` with only `['lang' => $lang]`. Lane 1's analyzer keeps `analyze_query()` as the legacy string-term shim unless callers request occurrence output, so the records have no `lang` and `assert_or_pending()` keeps the whole fixture pending even though the language-aware query API is present. This skips the English/Polish/German/Turkish/CJK term assertions that should become enforced.

   Required fix: make this test prefer `analyze_query_occurrences()` when available, or pass an occurrence-format option such as `['lang' => $lang, 'return' => 'occurrences']` for array-option analyzers. If supporting the Lane 2 string-language signature, inspect the second parameter type rather than only parameter count.

2. The per-language BM25 pending gate will not become enforced with Lane 4's `WP_FTS_StorageCompat`.

   References:
   - `/home/claude/indexer-lanes/tests-quality/tests/run.php:307`
   - `/home/claude/indexer-lanes/tests-quality/tests/run.php:323`
   - `/home/claude/indexer-lanes/tests-quality/tests/run.php:331`
   - `/home/claude/indexer-lanes/tests-quality/tests/run.php:340`
   - `/home/claude/indexer-lanes/tests-quality/tests/run.php:728`

   The fake storage accepts `?string $lang` on `get_doc_lengths()` and `get_meta()`, but its `put_doc()` and `add_meta()` still use the old arity. Lane 4's compatibility adapter treats storage as language-aware only when both sides of each contract advertise the new arity, so it calls this fake with `null` language and the test remains pending instead of enforcing the English partition score.

   Required fix: update the fake to advertise language-aware-compatible signatures, for example `put_doc(int $doc_id, int|string $primary_lang, array|string $lang_lengths, ?string $hash = null)` and `add_meta(int|string $lang, ?int $d_docs = null, ?int $d_len = null)`, while preserving legacy behavior for the current branch.

3. The optional BM25 reference harness mis-maps document IDs when `bm25s` returns corpus entries.

   References:
   - `/home/claude/indexer-lanes/tests-quality/tests/bm25_lucene_reference.py:65`
   - `/home/claude/indexer-lanes/tests-quality/tests/bm25_lucene_reference.py:76`
   - `/home/claude/indexer-lanes/tests-quality/tests/bm25_lucene_reference.py:77`

   With `corpus=DOC_IDS`, current `bm25s` returns the supplied corpus values as documents. The script then treats small integer document IDs as zero-based indexes, so doc ID `1` becomes `DOC_IDS[1] == 2`, doc ID `2` becomes `3`, and so on. That makes the optional harness fail or compare the wrong documents when `bm25s` is installed.

   Required fix: either omit `corpus` and map returned indexes through `DOC_IDS`, or keep `corpus=DOC_IDS` and treat returned values as final document IDs. Using non-index-like IDs such as `101, 202, ...` would make this regression obvious.

## Verification

- `php /home/claude/indexer-lanes/tests-quality/tests/run.php` -> exit 0, `10/15 tests passed, 5 pending`.
- `php -l /home/claude/indexer-lanes/tests-quality/tests/run.php` -> no syntax errors.
- `python3 -m py_compile /home/claude/indexer-lanes/tests-quality/tests/bm25_lucene_reference.py` -> passed.
- `python3 /home/claude/indexer-lanes/tests-quality/tests/bm25_lucene_reference.py` -> exit 2 because `bm25s` is not installed, matching the README skip contract.
- `git -C /home/claude/indexer-lanes/tests-quality diff --check HEAD^..HEAD` -> clean.

## Sources Consulted

- `bm25s` README: https://github.com/xhluca/bm25s
