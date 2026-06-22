# Task 1433: Docker Disposable Lifecycle Smoke Result

## Status

Task 1433 source branch: `refs/heads/task/1433-docker-disposable-lifecycle-smoke`.

Task 1433 source SHA: `13a413184af19d676a925ec4e5fbe465c66126c2`.

Review 1435 outcome: APPROVED for the original Docker disposable lifecycle smoke implementation.

Review 1435a outcome: REQUEST CHANGES. The branch was not promotion-ready because the task-owned result artifact was missing and the lifecycle wrapper/collector could classify an inner lifecycle `SKIP` as `pass`.

Task 1436 fix branch: `refs/heads/task/1436-fix-lifecycle-smoke-review1435a`.

The final pushed fix branch SHA is the remote value of `refs/heads/task/1436-fix-lifecycle-smoke-review1435a`; because this artifact is committed in that branch, embedding the commit's own SHA in the artifact would change the SHA.

## Recovery Summary

The original Task 1433 branch is not claimed as promotion-ready before this fix.

Task 1436 supplies the missing task-owned artifact and fixes the Review 1435a lifecycle proof bug:

- the inner lifecycle runner now writes a structured report file for skipped and passed outcomes;
- the Docker wrapper fails unless that report has schema `wp-fts-disposable-lifecycle-smoke-v1` and status `passed`;
- the wrapper emits a compact structured proof line for collector consumption;
- the release evidence collector requires the inner lifecycle proof before classifying the Docker lifecycle lane as `pass`;
- focused contracts prove skipped inner reports and wrapper-only `PASS` text do not become lifecycle proof.

## Verification Evidence After Fix

- `php -l indexer/tools/smoke-disposable-wordpress-lifecycle.php` -> exit 0.
- `php -l indexer/tools/collect-release-evidence.php` -> exit 0.
- `php -l indexer/tests/quality/disposable-lifecycle-smoke-contracts.php` -> exit 0.
- `php -l indexer/tests/quality/release-evidence-bundle-contracts.php` -> exit 0.
- `bash -n indexer/tools/run-disposable-lifecycle-smoke.sh` -> exit 0.
- `php indexer/tests/quality/disposable-lifecycle-smoke-contracts.php` -> exit 0; 7 focused lifecycle contracts passed.
- `php indexer/tests/quality/release-evidence-bundle-contracts.php` -> exit 0; 15 focused collector contracts passed, including skipped-inner-report and wrapper-PASS-without-proof regressions.
- `php -n indexer/tests/quality/disposable-lifecycle-smoke-contracts.php` -> exit 0.
- `php -n indexer/tests/quality/release-evidence-bundle-contracts.php` -> exit 0.
- `php indexer/tools/collect-release-evidence.php --release-target=direct-install --run-direct-install-readiness --timeout=120` -> exit 0; overall direct-install evidence passed and Docker lifecycle remained default `skip`.
- `timeout 420s indexer/tools/run-disposable-lifecycle-smoke.sh` -> exit 0; wrapper proof reported `inner_report_status=passed`.
- `timeout 480s php indexer/tools/collect-release-evidence.php --release-target=direct-install --run-direct-install-readiness --run-docker-lifecycle-smokes --timeout=300` -> exit 0; overall direct-install evidence passed and Docker lifecycle lane passed with `lifecycle_report_status=passed`.
- `git diff --check` -> exit 0.
- Changed-path/prohibited-artifact scan -> exit 0; no prohibited artifact paths were changed.

## Residual Risks

- Multisite lifecycle proof remains explicitly not run by this Docker lane.
- Docker proof depends on the local Docker daemon and current image availability.
- Public-submission readiness remains non-target evidence for these direct-install/operator lifecycle checks.

## Promotion Readiness

The original Task 1433 SHA should not be promoted alone. The Task 1436 fix branch is ready for review after non-force push and remote ref verification.
