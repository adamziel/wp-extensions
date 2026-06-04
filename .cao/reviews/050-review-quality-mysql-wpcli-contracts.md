# Review 050: Quality MySQL WP-CLI Contracts

## Target

Worktree: `/home/claude/indexer-quality-lanes/mysql-wpcli-contracts`
Branch: `quality/mysql-wpcli-contracts`
Commit: `47029af Add MySQL WP-CLI contract quality tests`
Remote: `indexer/quality-mysql-wpcli-contracts`

## Context

This lane should add fake WordPress/MySQL contract coverage for schema, prepared SQL behavior, storage overloads, canonicalization, and WP-CLI options.

## Verification Observed

After one fix, `php tests/run.php` passed in the lane and showed:

```text
49/49 tests passed, 0 pending
```

Earlier failure asserted an SQL SELECT during optimize that the implementation does not issue. The final commit should be checked to ensure it asserts the real intended contract rather than weakening the test.

## Review Focus

Check whether:

- New MySQL/WP-CLI tests are meaningful and assert real contracts.
- Fake `$wpdb` coverage verifies binary namespace terms, language partitions, tombstones, optimize/delete, meta/doc length overloads, and CLI args.
- The prior optimize/delete expectation was corrected in a technically valid way.
- No live WordPress/MySQL requirement was introduced.
- The lane is structured so it can integrate cleanly with harness discovery.

Write result to:

```text
/home/claude/indexer/.cao/reviews/050-review-quality-mysql-wpcli-contracts-result.md
```

Return APPROVED only if no required fixes remain.
