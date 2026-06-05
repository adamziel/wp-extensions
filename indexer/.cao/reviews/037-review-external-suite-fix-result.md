# Review 037 Result: External Snowball Suite Fix

Verdict: APPROVED

## Findings

No required fixes found.

## Review Notes

- The Snowball harness is an honest external compliance check for the languages that `WP_FTS_SnowballStemmer` advertises. In `/home/claude/indexer-external-suite/src/Stemmer.php:59`, the adapter support map is reduced to `ca` and `nl`; in `/home/claude/indexer-external-suite/tests/snowball-compliance.php:163`, every non-variant dataset with a supported code proceeds into fixture comparison instead of being skipped.
- The 35 skips are legitimate and documented. Languages not implemented by Wamania are reported as unsupported, variant datasets are reported as variants, and Wamania-exposed languages that diverge from current official Snowball data are explicitly labeled with that divergence in `/home/claude/indexer-external-suite/tests/snowball-compliance.php:11` and the per-language metadata at lines 19, 27, 30-32, 38, 42, 46-48, and 51-52.
- Catalan and Dutch Porter are genuinely tested against official `voc.txt` and `output.txt`. The harness reads both files at `/home/claude/indexer-external-suite/tests/snowball-compliance.php:177` and compares every line pair at lines 184-215. The observed run matched 48,897 Catalan pairs and 45,670 Dutch-Porter pairs.
- The `nl` mapping is transparent: `/home/claude/indexer-external-suite/tests/snowball-compliance.php:20` skips the newer `dutch` dataset as a variant mismatch, while `/home/claude/indexer-external-suite/tests/snowball-compliance.php:26` tests `dutch_porter` with code `nl`. This matches Wamania's factory behavior for `nl`.
- The harness exits non-zero for real supported-dataset failures. A temporary run with a deliberately altered Catalan expected output reported one Catalan failure and returned status `1`.
- The documentation explains how to run the harness and what pass/skip means in `/home/claude/indexer-external-suite/docs/snowball-compliance.md:3`, including the limited compliant support set and the reason Wamania-exposed divergent algorithms are treated as unsupported.
- The changed files are scoped to the compliance docs, Snowball adapter mapping, regular tests, and the compliance harness. I did not find unrelated code changes.

## Verification Performed

- `php /home/claude/indexer-external-suite/tests/run.php` -> 25/25 passed
- `composer test` -> 25/25 passed
- `SNOWBALL_DATA_DIR=/home/claude/.cache/snowball-data php /home/claude/indexer-external-suite/tests/snowball-compliance.php` -> 2 pass, 35 skip, 0 fail
- Direct Wamania-vs-official-data check for Wamania-exposed languages: Catalan and Dutch Porter had 0 mismatches; the skipped Wamania-exposed datasets all had mismatches against current official Snowball data.
- Negative supported-fixture check with modified Catalan output -> harness reported `[FAIL] Catalan` and returned status `1`.
- PHP lint over repo PHP files excluding `vendor` -> no syntax errors
- `git diff --check` -> clean
