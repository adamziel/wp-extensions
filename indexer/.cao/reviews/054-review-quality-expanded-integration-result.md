# Review 054 Result: Quality Expanded Integration

Status: REQUIRED FIXES

## Findings

1. The final integration does not enforce the requested `>=1500` check/scenario gate by default.

   - `/home/claude/indexer-quality-integration/tests/run.php:69`
   - `/home/claude/indexer-quality-integration/tests/run.php:117`
   - `/home/claude/indexer-quality-integration/tests/run.php:2346`
   - `/home/claude/indexer-quality-integration/composer.json:14`
   - `/home/claude/indexer-quality-integration/tests/README.md:12`

   The integrated harness still sets `WP_FTS_DEFAULT_MIN_CHECKS` to `40`, and `composer test` runs plain `php tests/run.php` without setting `WP_FTS_MIN_CHECKS`. As verified locally, both normal `php tests/run.php` and `composer test` pass while reporting `minimum checks=40`, even though this is the final quality-expanded integration and the README says final integration should raise the gate to at least `1500` executed checks/scenarios. The explicit `WP_FTS_MIN_CHECKS=1500 php tests/run.php` command passes, but the regular project test entry point does not protect the final quality bar.

   Required fix: make the final integration's default/standard gate enforce at least `1500` checks, either by raising `WP_FTS_DEFAULT_MIN_CHECKS` to `1500` for this integration or by making the standard Composer test command set `WP_FTS_MIN_CHECKS=1500`, and update the README wording so it no longer describes the integrated branch as an isolated lane.

## Review Notes

- Verified `/home/claude/indexer-quality-integration` is at `477462fd145895d48a1b11649bb5e6c02c5b9bd2` on `integration/quality-expansion`, with clean status before review.
- Confirmed all approved quality branch heads are ancestors of the integration commit:
  `33093f22973e5682964b1ac5d64a32c78794827b`,
  `82c7f7744714177b91658b80bc824db3d7582929`,
  `b3142785674d96721e68a9d4b24edf111f3b815f`,
  `47029afff81052cac96cd2f4731e79016d51ee4e`, and
  `505bda3dfe9e37823cf631b2f849fd345cb63603`.
- The harness discovery path is otherwise reconciled: `tests/run.php` discovers sorted `tests/quality/*.php` files and the sentinel discovery test verifies the files are included exactly once.
- The reported `12233` checks/scenarios are substantive enough for the requested bar. They come from assertion helpers, generated corpus comparisons, storage/search oracle checks, external reference assertions, and lane contribution counters rather than a single no-op inflation loop.
- Analyzer source changes are covered by the expanded corpus: SVG is added to skipped ancestors in `/home/claude/indexer-quality-integration/src/Analyzer.php:62`, combining marks are included in tokenization in `/home/claude/indexer-quality-integration/src/LanguagePipeline.php:122`, and decomposed fold maps are present in `/home/claude/indexer-quality-integration/src/Normalizer.php:206`.
- Optional Python BM25 behavior remains explicit: the standalone harness exits `2` with the `bm25s` missing message, while the PHP external reference tests still run local BM25 checks.
- I did not find pending tests in this environment; the local harness runs report `pending=0`.

## Verification

- `git -C /home/claude/indexer-quality-integration rev-parse HEAD` -> `477462fd145895d48a1b11649bb5e6c02c5b9bd2`
- `git -C /home/claude/indexer-quality-integration status --short` -> clean
- `git merge-base --is-ancestor <approved-quality-head> 477462fd145895d48a1b11649bb5e6c02c5b9bd2` -> exit `0` for all five approved quality heads
- `php tests/run.php` -> passed: `79/79 named tests passed; failures=0; pending=0; checks/scenarios=12233; minimum checks=40; final target>=1500`
- `composer test` -> passed: `79/79 named tests passed; failures=0; pending=0; checks/scenarios=12233; minimum checks=40; final target>=1500`
- `WP_FTS_MIN_CHECKS=1500 php tests/run.php` -> passed: `79/79 named tests passed; failures=0; pending=0; checks/scenarios=12233; minimum checks=1500; final target>=1500`
- `php -n tests/run.php` -> passed: `79/79 named tests passed; failures=0; pending=0; checks/scenarios=12233; minimum checks=40; final target>=1500`
- `SNOWBALL_DATA_DIR=/home/claude/.cache/snowball-data php tests/snowball-compliance.php` -> passed: `2 pass, 35 skip, 0 fail`
- `python3 tests/bm25_lucene_reference.py` -> exit `2`, explicit optional dependency message: `Optional dependency bm25s is not installed`
- `find . -path ./vendor -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l` -> clean
- `git diff --check` -> clean
- `git status --short --branch` -> clean on `integration/quality-expansion`
