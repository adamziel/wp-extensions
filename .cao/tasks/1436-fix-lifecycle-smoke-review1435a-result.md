# Task 1436: Fix Lifecycle Smoke Review 1435a Blockers

## Status

Implemented on `refs/heads/task/1436-fix-lifecycle-smoke-review1435a`.

Base source branch: `refs/heads/task/1433-docker-disposable-lifecycle-smoke` at `13a413184af19d676a925ec4e5fbe465c66126c2`.

Current main during task brief: `d8d13225c86430547710792c383830c88edc1613`.

The final pushed branch SHA is the remote value of `refs/heads/task/1436-fix-lifecycle-smoke-review1435a`; because this artifact is committed in that branch, embedding the commit's own SHA in the artifact would change the SHA.

## Fix Summary

- `indexer/tools/smoke-disposable-wordpress-lifecycle.php` now accepts `--report-file=PATH` and writes the structured lifecycle report for `passed`, `skipped`, and `failed` outcomes.
- `indexer/tools/run-disposable-lifecycle-smoke.sh` mounts a disposable `/smoke-reports` directory, requires the inner lifecycle report schema `wp-fts-disposable-lifecycle-smoke-v1`, and fails the wrapper unless the inner status is exactly `passed`.
- The wrapper emits a compact structured proof record, `wp-fts-disposable-lifecycle-wrapper-proof-v1`, with `inner_report_status`, so the collector can verify pass/fail/skip without relying on long truncated subprocess output.
- `indexer/tools/collect-release-evidence.php` now classifies the Docker lifecycle lane as `pass` only when the wrapper output includes a parseable inner lifecycle proof with status `passed`. Inner `skipped`, `skip`, or `unavailable` statuses are non-pass.
- Focused contracts cover skipped report-file output, skipped inner lifecycle proof, wrapper `PASS` text without inner proof, and the happy path with inner status `passed`.

## Files Changed

- `indexer/tools/smoke-disposable-wordpress-lifecycle.php`
- `indexer/tools/run-disposable-lifecycle-smoke.sh`
- `indexer/tools/collect-release-evidence.php`
- `indexer/tests/quality/disposable-lifecycle-smoke-contracts.php`
- `indexer/tests/quality/release-evidence-bundle-contracts.php`
- `.cao/tasks/1436-fix-lifecycle-smoke-review1435a-result.md`
- `.cao/tasks/1433-docker-disposable-lifecycle-smoke-result.md`

## Verification

- `php -l indexer/tools/smoke-disposable-wordpress-lifecycle.php` -> exit 0.
- `php -l indexer/tools/collect-release-evidence.php` -> exit 0.
- `php -l indexer/tests/quality/disposable-lifecycle-smoke-contracts.php` -> exit 0.
- `php -l indexer/tests/quality/release-evidence-bundle-contracts.php` -> exit 0.
- `bash -n indexer/tools/run-disposable-lifecycle-smoke.sh` -> exit 0.
- `php indexer/tests/quality/disposable-lifecycle-smoke-contracts.php` -> exit 0; 7 focused lifecycle contracts passed.
- `php indexer/tests/quality/release-evidence-bundle-contracts.php` -> exit 0; 15 focused collector contracts passed.
- `php -n indexer/tests/quality/disposable-lifecycle-smoke-contracts.php` -> exit 0; 7 focused lifecycle contracts passed.
- `php -n indexer/tests/quality/release-evidence-bundle-contracts.php` -> exit 0; 15 focused collector contracts passed.
- `php indexer/tools/collect-release-evidence.php --release-target=direct-install --run-direct-install-readiness --timeout=120` -> exit 0; decoded summary `overall=pass`, `docker_lifecycle=skip`.
- `command -v docker && docker info --format '{{.ServerVersion}}'` -> exit 0; Docker daemon `27.5.1`.
- `timeout 420s indexer/tools/run-disposable-lifecycle-smoke.sh` -> exit 0; emitted wrapper proof `inner_report_status":"passed"` and `PASS: Docker disposable lifecycle smoke completed.`
- `timeout 480s php indexer/tools/collect-release-evidence.php --release-target=direct-install --run-direct-install-readiness --run-docker-lifecycle-smokes --timeout=300` -> exit 0; decoded summary `overall_status=pass`, `docker_disposable_lifecycle_smoke=pass`, `lifecycle_report_status=passed`.
- Targeted regression is covered by `php indexer/tests/quality/release-evidence-bundle-contracts.php`: the skipped inner report case returns lane `skip`, and wrapper `PASS` text without inner proof returns lane `fail`.
- `git diff --check` -> exit 0.
- Changed-path/prohibited-artifact scan over the worktree diff -> exit 0; no `.env`, `*.pem`, key, or secret artifact path changes were found.

Broad full-suite harnesses were not run because the implementation is limited to lifecycle smoke tooling, collector classification, focused contracts, and task artifacts.

## Residual Risks

- The Docker lifecycle lane remains single-site only; multisite lifecycle proof is still explicitly recorded as not run.
- Docker evidence depends on the locally available Docker daemon and upstream `wordpress`/`mariadb` images.
- The collector stores bounded excerpts; full inner lifecycle output is intentionally summarized by the compact wrapper proof.

## Review Readiness

Ready for review after non-force push of `refs/heads/task/1436-fix-lifecycle-smoke-review1435a`.
