# Task 043: Add Quality Harness Discovery and Check Metrics

## Context

Read the shared contract first:

```text
/home/claude/indexer/.cao/tasks/042-quality-expansion-contract.md
```

Work from current trunk commit:

```text
581c3a4e8893c48f28d341d6f4e86deb7693420a
```

## Required Work

Create a `quality/harness-metrics` branch/worktree and improve the PHP test harness so it can support a serious high-volume suite.

Implement:

- Automatic discovery/loading of `tests/quality/*.php`.
- A shared way for tests to increment an executed check/scenario counter.
- Assertion helpers should increment the check counter where appropriate.
- A helper for generated data-driven tests, e.g. `record_check($label = null)` or equivalent.
- Final output must include named tests, executed checks/scenarios, failures, and pending count.
- Add a configurable minimum check count gate, defaulting to the current suite count while this lane is isolated, and documented target `>=1500` for final integration.
- Preserve existing output readability and exit codes.
- Do not mask failures as pending.

Add focused tests proving:

- Discovered quality test files are loaded.
- Check/scenario counts increase for assertions and generated loops.
- The minimum-count gate fails when deliberately set too high.
- Existing 40 named tests still pass.

## Acceptance

Run and report:

```bash
php tests/run.php
composer test
php -n tests/run.php
SNOWBALL_DATA_DIR=/home/claude/.cache/snowball-data php tests/snowball-compliance.php
find . -path ./vendor -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
git diff --check
git status --short --branch
```

Commit the result and send the commit SHA plus output summary back to terminal `da2963f2`.
