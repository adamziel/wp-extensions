# Review Result: 011 Lane 1 Analyzer Core

Status: NOT APPROVED

## Required Fixes

1. Prevent element `lang` scopes from leaking into same-depth siblings, including omitted-close HTML.

   References:
   - `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php:292`
   - `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php:302`
   - `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php:304`
   - `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php:396`
   - `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php:400`
   - `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php:425`
   - `/home/claude/indexer-lanes/analyzer-core/src/Analyzer.php:871`

   `extractWithProcessor()` stores language by breadcrumb depth, but `pruneLanguageStack()` only removes scopes deeper than the current token. If the parser advances from one element to a same-depth sibling without an explicit closer token, the old depth entry remains. The next sibling then inherits the previous sibling's `lang` even when it has no `lang` attribute. This violates the lane's sibling/nested language-scope requirement.

   The fallback parser has the same bug for valid omitted-close HTML. For example:

   ```bash
   php -r 'require "/home/claude/indexer-lanes/analyzer-core/src/bootstrap.php"; $a = new WP_FTS_Analyzer(["default_lang"=>"en"]); var_export($a->analyze_content("<p lang=pl>Lodz<p>Hello"));'
   ```

   Current result marks both `lodz` and `hello` as `pl`. `hello` should resolve to the document/default language (`en`) because the second `<p>` is a sibling, not a descendant, of the first language-scoped paragraph.

   Required fix: make language tracking element-scoped rather than only depth-scoped. On same-depth sibling entry, clear or replace the previous element's language scope unless the language is inherited from an actual ancestor. In the fallback parser, handle optional end-tag cases at least for common same-tag paragraph/list/table siblings, or otherwise update the stack so a new sibling cannot remain nested under the previous sibling's `lang`. Add regression tests for omitted-close siblings, explicit-close siblings, and nested overrides that correctly restore the parent language.

## Notes

- The prior `WP_HTML_Processor` double-decode issue appears fixed in the processor path: `get_modifiable_text()` is only read after the `#text` check and is no longer passed through `html_entity_decode()`.
- The prior optional-extension issue appears fixed for this lane: `mb_convert_encoding()`, `mb_strtolower()`, `mb_strlen()`, `transliterator_transliterate()`, and `iconv()` are all guarded.
- Search/stats integration should work with this lane's array-option analyzer signatures. The stemmers/dialects lane rewrites `src/Analyzer.php` and `merge-tree` reports direct conflicts, so final merge resolution must preserve this lane's processor-text invariant, language extraction, CJK/mixed-script tokenization, and extension guards while adopting the pipeline/stemmer classes.

## Verification

- `php /home/claude/indexer-lanes/analyzer-core/tests/run.php`: 15/15 tests passed.
- `php -n /home/claude/indexer-lanes/analyzer-core/tests/run.php`: 15/15 tests passed.
- `php -l /home/claude/indexer-lanes/analyzer-core/src/Analyzer.php`: no syntax errors.
- Targeted omitted-close language-scope probe: failed as described above.
