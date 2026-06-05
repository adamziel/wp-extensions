# Task 047: Add MySQL and WP-CLI Contract Quality Tests

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

Create a `quality/mysql-wpcli-contracts` branch/worktree and add high-diversity fake-WordPress/MySQL tests, preferably in:

```text
tests/quality/mysql-wpcli-contracts.php
```

Target at least 250 meaningful executed checks from this lane.

Cover:

- MySQL DDL/schema strings for binary term keys, docs language/hash/delete fields, doc-length partition table, and per-language meta.
- Prepared SQL behavior for namespaced binary terms, language params, tombstones, optimize/delete, and ON DUPLICATE KEY updates.
- `put_doc()`, `get_doc()`, `get_doc_lengths()`, `get_meta()`, `add_meta()` legacy and language-aware overloads.
- Bad/edge language inputs canonicalized or rejected consistently.
- WP-CLI `reindex`/`search` options: `--lang`, `--language`, post type/status aliases, limit, batch size, source filters.
- CLI behavior under missing posts, empty content, deleted/reindexed posts, mixed language posts, invalid args.
- No automatic hooks added.

Use fake `$wpdb` and fake WP/WP-CLI functions only. Do not require a live WordPress install or live MySQL for this lane.

## Acceptance

Run and report:

```bash
php tests/run.php
composer test
php -n tests/run.php
find . -path ./vendor -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
git diff --check
git status --short --branch
```

Commit the result and send the commit SHA plus approximate executed check contribution back to terminal `da2963f2`.
