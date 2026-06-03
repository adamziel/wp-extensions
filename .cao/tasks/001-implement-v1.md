# Developer Task: Implement V1 WordPress Pure-PHP FTS Engine

Project root: `/home/claude/indexer`
Specification: `/home/claude/indexer/goal.md`

Implement the v1 described in the specification. The directory currently contains only the goal file, so scaffold the project in-place.

Use these default decisions from section 10 unless the spec requires otherwise:

- Boost combination: max-over-ancestors.
- Skip ancestors: `SCRIPT`, `STYLE`, `NOSCRIPT`, `TEMPLATE`, `NAV`, `ASIDE`, `FOOTER`, `FORM`.
- Boosts: `TITLE=5`, `H1=4`, `H2=3`, `H3=2`, `STRONG=2`, `EM=1.5`, `B=2`.
- Diacritic folding: on.
- Stemming: no bundled default stemmer.
- Boolean mode: OR default, AND supported.
- BM25: `k1=1.2`, `b=0.75`.
- Positions: out for v1.
- Field weighting: weighted term frequency.
- `reindex_all`: default to published posts of type `post`; make status/type configurable.

Expected implementation:

1. Scaffold a PHP/Composer project suitable for WordPress plugin/library use.
2. Implement analyzer stages:
   - HTML extraction using `WP_HTML_Processor` when available.
   - Read text only from `#text` tokens.
   - Guard `create_fragment()` returning null.
   - Fall back safely when WordPress HTML API is unavailable in tests.
   - Tokenization, lowercase normalization, diacritic folding, stopword/min/max filtering.
3. Implement postings varint encode/decode with doc-id deltas and weighted TF.
4. Implement storage interface plus:
   - In-memory storage for tests/oracle support.
   - File storage backend.
   - MySQL storage skeleton using `$wpdb`, table creation SQL/dbDelta-compatible formatting where practical, prepared operations, and exact binary term semantics.
5. Implement indexer:
   - `index_document`, `delete_document`, `reindex_all`, `flush`, `optimize`.
   - Hash skip-if-unchanged.
   - Tombstones and compaction behavior.
6. Implement searcher:
   - Query analysis parity.
   - OR/AND boolean modes.
   - BM25 scoring in PHP.
   - Return ranked `doc_id`/score results.
7. Add WP-CLI registration for reindex/search/delete/optimize if WP-CLI is present.
8. Add a focused test suite that can run in this environment without a full WordPress install:
   - Analyzer script/style/nav exclusion and heading boost behavior, using a fallback parser if WP core classes are absent.
   - Varint/postings round trips.
   - Brute-force oracle parity over deterministic generated corpora.
   - Index/query parity.
   - Boolean and edge cases.
   - Incremental vs full reindex convergence using the in-memory/file backends.

Constraints:

- Do not read or output secrets such as `.env`, `*.pem`, `~/.ssh/*`, or AWS credential files.
- Preserve `/home/claude/indexer/goal.md`.
- Report all created/modified artifact paths as absolute paths.
- Run the available test command(s) and report exact results.

Deliverable back to the supervisor:

- Summary of implementation.
- Absolute paths for key artifacts.
- Test command(s) run and results.
- Any gaps against `/home/claude/indexer/goal.md`.
