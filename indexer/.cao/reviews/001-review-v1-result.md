# Review Result: 001 V1 FTS Implementation

Status: NOT APPROVED

## Required Fixes

1. Guard or declare the analyzer's optional PHP extension dependencies.

   References:
   - `/home/claude/indexer/composer.json:6`
   - `/home/claude/indexer/composer.json:7`
   - `/home/claude/indexer/src/Analyzer.php:335`
   - `/home/claude/indexer/src/Analyzer.php:336`
   - `/home/claude/indexer/src/Analyzer.php:416`

   The package contract currently advertises only `php >=8.1`, but the analyzer can call optional extensions unguarded. `tokenizeText()` calls `mb_convert_encoding()` on the malformed UTF-8 recovery path, and `foldDiacritics()` calls `iconv()` whenever the manual Polish map and `Transliterator` path do not finish the fold. On a PHP runtime without loaded extensions, the existing test suite fails with `Call to undefined function iconv()`, and an invalid UTF-8 query fatals with `Call to undefined function mb_convert_encoding()`.

   Required fix: either add explicit Composer/platform requirements and plugin activation/runtime checks for the needed extensions, or make both paths fully guarded with no-extension fallbacks. Since diacritic folding is a v1 requirement, the fix should also preserve the current `Wrocław`, `Łódź`, and `café` behavior.

2. Do not HTML-decode `WP_HTML_Processor` text a second time.

   References:
   - `/home/claude/indexer/src/Analyzer.php:198`
   - `/home/claude/indexer/src/Analyzer.php:203`
   - `/home/claude/indexer/src/Analyzer.php:204`
   - `/home/claude/indexer/src/Analyzer.php:285`

   `extractWithProcessor()` reads `get_modifiable_text()` from `#text` tokens, then runs `html_entity_decode()` again. WordPress' HTML API already decodes normal text nodes before returning modifiable text, so production indexing with `WP_HTML_Processor` can diverge from the fallback parser and from rendered text for literal entity text such as `&amp;copy;` or `&amp;amp;`. This is not covered by the current no-WordPress fallback tests.

   Required fix: remove the extra `html_entity_decode()` from the `WP_HTML_Processor` path while keeping decoding in the fallback parser for raw split text. Add a regression test using either real `WP_HTML_Processor` or a fake processor that returns already-decoded text.

## Residual Risks

- The MySQL backend is syntax-reviewed only in this environment; there is no integration or differential test against a real `$wpdb`/MySQL table for table creation, binary postings persistence, or `dbDelta()` behavior.
- BM25 is covered by a brute-force oracle, but not by an external Lucene-IDF reference. The implementation appears to match the spec formula, but the test oracle shares the same local formula.
- `reindex_all()` reindexes matching source rows but does not purge posts that have fallen out of scope. This is acceptable only if callers explicitly invoke `delete_document()` for unpublished/deleted posts or rebuild into fresh storage.

## Verification Run

- `php /home/claude/indexer/tests/run.php`: 10/10 tests passed.
- `composer test`: 10/10 tests passed; Composer emitted only the root package version warning.
- `php -l` over `indexer.php`, `src/*.php`, and `tests/run.php`: no syntax errors.
- `node /home/claude/.codex/skills/wp-project-triage/scripts/detect_wp_project.mjs /home/claude/indexer`: project kind reported `unknown`, with Composer PHP tooling detected. Manual inspection confirms a standalone WordPress plugin/library.
- `php -n /home/claude/indexer/tests/run.php`: 2/10 tests failed because `iconv()` was undefined.
- `php -n -r 'require "/home/claude/indexer/src/bootstrap.php"; $a=new WP_FTS_Analyzer(["fold_diacritics"=>false]); var_export($a->analyze_query("bad\xffutf"));'`: fataled because `mb_convert_encoding()` was undefined.

## Sources Consulted

- WordPress Code Reference, `WP_HTML_Processor::get_modifiable_text()`: https://developer.wordpress.org/reference/classes/wp_html_processor/get_modifiable_text/
- WordPress Code Reference, `WP_HTML_Tag_Processor::get_modifiable_text()`: https://developer.wordpress.org/reference/classes/wp_html_tag_processor/get_modifiable_text/
