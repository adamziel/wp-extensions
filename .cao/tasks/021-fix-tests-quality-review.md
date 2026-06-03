# Developer Fix Task: Lane 6 Tests/Quality Review Fixes

Worktree: `/home/claude/indexer-lanes/tests-quality`
Branch: `lanes/tests-quality`
Current lane commit: `bd75666580a70c2ab2c1bafad45af74e5bee81a4`
Review result: `/home/claude/indexer/.cao/reviews/016-review-tests-quality-result.md`

Fix the three required reviewer findings for Lane 6. Do not work in other lane worktrees.

## Required Fixes

### 1. Make T8 query analyzer fixtures enforce after language-aware analyzer lands

The helper currently calls `analyze_query()` with only `['lang' => $lang]`. Lane 1 keeps `analyze_query()` as a legacy plain-string shim unless occurrence output is requested, so records have no `lang` and the fixture remains pending even when the language-aware API exists.

Required behavior:

- Prefer `analyze_query_occurrences()` when available.
- Otherwise request occurrence-format output via `analyze_query($query, ['lang' => $lang, 'return' => 'occurrences'])` or equivalent.
- If supporting Lane 2's string-language signature, inspect the second parameter type rather than only parameter count.
- Keep compatibility with the current baseline where these checks may still be pending.

### 2. Make the fake BM25 storage advertise language-aware signatures

The fake storage accepts `?string $lang` on `get_doc_lengths()` and `get_meta()`, but `put_doc()` and `add_meta()` still advertise old arity. Lane 4's `WP_FTS_StorageCompat` detects that as non-language-aware and passes `null`, so the pending gate does not become enforced.

Required behavior:

- Update the fake storage signatures to be language-aware-compatible, e.g.:
  - `put_doc(int $doc_id, int|string $primary_lang, array|string $lang_lengths, ?string $hash = null)`
  - `add_meta(int|string $lang, ?int $d_docs = null, ?int $d_len = null)`
- Preserve legacy behavior for the current branch.
- Ensure the per-language BM25 gate becomes enforceable once Lane 4's compatibility adapter is present.

### 3. Fix optional `bm25s` doc-id mapping

The optional Python reference harness passes `corpus=DOC_IDS`, and current `bm25s` returns those supplied values. The script then treats small integer doc IDs as zero-based indexes, so it compares the wrong documents.

Required behavior:

- Either omit `corpus` and map returned indexes through `DOC_IDS`, or keep `corpus=DOC_IDS` and treat returned values as final doc IDs.
- Prefer non-index-like doc IDs such as `101`, `202`, etc. so mapping mistakes are obvious.
- Keep the documented exit-2 behavior when `bm25s` is not installed.

## Cleanup

The worktree currently has an untracked generated directory from verification:

- `/home/claude/indexer-lanes/tests-quality/tests/__pycache__/`

Remove it or add an appropriate ignore rule if generated again. Do not commit generated cache files.

## Verification

Run and report:

- `php /home/claude/indexer-lanes/tests-quality/tests/run.php`
- `composer test` if available
- `php -l /home/claude/indexer-lanes/tests-quality/tests/run.php`
- `python3 -m py_compile /home/claude/indexer-lanes/tests-quality/tests/bm25_lucene_reference.py`
- `python3 /home/claude/indexer-lanes/tests-quality/tests/bm25_lucene_reference.py` (expected exit 2 is acceptable if `bm25s` is not installed)
- `git -C /home/claude/indexer-lanes/tests-quality diff --check`
- `git -C /home/claude/indexer-lanes/tests-quality status --short --branch`

Commit the fix on `lanes/tests-quality` and report the new commit SHA, changed absolute paths, commands/results, and remaining assumptions.
