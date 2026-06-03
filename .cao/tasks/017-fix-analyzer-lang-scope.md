# Developer Fix Task: Lane 1 Analyzer Language Scope Leak

Worktree: `/home/claude/indexer-lanes/analyzer-core`
Branch: `lanes/analyzer-core`
Current lane commit: `4d6bf1c46d62e108a61eb9df014d0f150c05ede0`
Review result: `/home/claude/indexer/.cao/reviews/011-review-analyzer-core-result.md`

Fix the required reviewer finding for Lane 1. Do not work in other lane worktrees.

## Required Fix

Prevent element `lang` scopes from leaking into same-depth siblings, including omitted-close HTML.

The failing probe from the reviewer:

```sh
php -r 'require "/home/claude/indexer-lanes/analyzer-core/src/bootstrap.php"; $a = new WP_FTS_Analyzer(["default_lang"=>"en"]); var_export($a->analyze_content("<p lang=pl>Lodz<p>Hello"));'
```

Current behavior marks both `lodz` and `hello` as `pl`.
Expected behavior: `lodz` is `pl`; `hello` resolves to the document/default language `en`.

The same class of bug can affect:

- Same-depth siblings after explicit closing tags.
- Omitted-close HTML for common optional-end elements.
- Nested overrides that should restore the parent language after the nested element ends.

## Implementation Guidance

- Make `WP_HTML_Processor` language tracking element-scoped, not just depth-scoped.
- On same-depth sibling entry, clear or replace the previous element language scope unless it is inherited from a real ancestor.
- In the fallback parser, handle optional end-tag cases at least for common same-tag paragraph/list/table siblings so a new sibling cannot remain nested under the previous sibling's `lang`.
- Preserve the already-approved parts of Lane 1:
  - Only read `#text` tokens from `WP_HTML_Processor`.
  - Do not double-decode `get_modifiable_text()`.
  - Keep optional extension guards.
  - Keep mixed-script/CJK tokenization behavior.
  - Keep compatibility shims for existing indexer/searcher callers.

## Tests To Add

Add regression coverage for:

- Omitted-close siblings: `<p lang=pl>Lodz<p>Hello` -> `lodz:pl`, `hello:en`.
- Explicit-close siblings: `<p lang=pl>Lodz</p><p>Hello</p>` -> `hello:en`.
- Nested overrides: parent language restores after child language override ends.
- At least one fallback-parser path case, since no full WordPress install is required.

## Verification

Run and report:

- `php /home/claude/indexer-lanes/analyzer-core/tests/run.php`
- `php -n /home/claude/indexer-lanes/analyzer-core/tests/run.php`
- `php -l /home/claude/indexer-lanes/analyzer-core/src/Analyzer.php`
- `git -C /home/claude/indexer-lanes/analyzer-core diff --check`
- `git -C /home/claude/indexer-lanes/analyzer-core status --short --branch`

Commit the fix on `lanes/analyzer-core` and report the new commit SHA, changed absolute paths, commands/results, and any remaining assumptions.
