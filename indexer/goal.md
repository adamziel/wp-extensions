# Specification: Pure-PHP HTML-Aware Full-Text Search for WordPress

**Status:** draft for implementation
**Audience:** implementer model / engineer
**Author intent:** Own the index end to end. No MySQL `FULLTEXT`, no SQLite FTS5. All
analysis and scoring in pure PHP. Indexing is HTML-structure-aware via
`WP_HTML_Processor`. Index is persisted to a custom table through `$wpdb`. Indexing
is invoked explicitly (CLI / batch / direct call) — **no `save_post` or other action
hooks**.

---

## 1. Goals and non-goals

### Goals
- Extract indexable text from HTML using `WP_HTML_Processor`, with full control over
  which elements are skipped (e.g. `<script>`, `<style>`, `<nav>`) and which are
  boosted (e.g. `<h1>`–`<h3>`, `<title>`, `<strong>`).
- Maintain a self-owned inverted index in a custom MySQL table written via `$wpdb`.
- Rank with BM25 (optionally field-weighted) computed in PHP.
- **Support stemming and multiple languages and dialects as a first-class concern**, not
  a bolt-on: a per-language analysis pipeline, per-language stemming, dialect-aware
  normalization, and correct per-language relevance statistics. The engine must index a
  multilingual corpus (including mixed-language documents) and search each language with
  the right analyzer.
- Keep the storage layer behind an interface so the same engine can run against a
  MySQL table on a normal host and against a flat file in WordPress Playground.
- Be invokable explicitly: a method to index one document, a batch reindex that pulls
  rows via `$wpdb`, and a delete.

### Non-goals (v1)
- No automatic indexing on post save. The host application decides when to (re)index.
- No phrase/proximity search unless positions are enabled (see §4.4). Default off.
- No faceting, no geo, no fuzzy/typo tolerance. Boolean AND/OR only.
- No reliance on any C-backed search engine (FTS5, MySQL FULLTEXT, Elastic, etc.).

---

## 2. Architecture

Four separable components. Keep them decoupled; the analyzer and storage are the two
that will change most.

```
                 ┌──────────────┐     terms+weights     ┌──────────────┐
   HTML  ───────▶│  Analyzer    │──────────────────────▶│  Indexer     │
                 │ (WP_HTML_…)  │                        │  (build)     │
                 └──────────────┘                        └──────┬───────┘
                        ▲                                        │ writes
   query string ────────┘ (same analyzer, HTML stage off)       ▼
                                                          ┌──────────────┐
   query terms ──────────────────────────────────────────│  Storage     │
                                                          │  backend     │
   ranked doc_ids ◀──────────  Searcher (BM25, PHP) ◀─────│ (MySQL/file) │
                                                          └──────────────┘
```

The **analyzer is shared** between indexing and querying. The only difference is the
HTML-extraction stage runs at index time and not at query time. If the two paths
tokenize differently, recall breaks — treat that as an invariant.

---

## 3. The analyzer

### 3.1 Pipeline
A composable chain (model it on Whoosh's tokenizer-plus-filters design). **The pipeline
is per-language**: language resolution happens first and selects which tokenizer,
normalizer, stopword list, and stemmer run for a given text segment.

0. **Language resolution** (index and query paths) — determine the language/dialect of
   the segment (§3.3).
1. **HTML extraction** (index path only) — `WP_HTML_Processor`, emits
   `(text, weight, lang)` segments.
2. **Tokenization** — split into terms; rules depend on script/language (§3.4).
3. **Normalization** — lowercase, fold, dialect-normalize (§3.5).
4. **Stemming** — language-specific (§3.6).
5. **Stopword removal** — optional, per-language list.

Each stage is replaceable and resolved per language. The output of the analyzer is an
ordered list of normalized terms (each tagged with its language, and on the index path
with a weight per occurrence).

### 3.2 HTML extraction with `WP_HTML_Processor`

Use the **HTML Processor**, not the Tag Processor: we need the ancestor chain to decide
skip/boost, and `get_breadcrumbs()` provides it. For `post_content` (a body-context
fragment) construct with `create_fragment()`. For full standalone HTML documents
(`<head>`, `<title>`, etc.) use `create_full_parser()` instead — confirm it exists in
the target WP version before relying on it.

Confirmed API (all current in core):
- `WP_HTML_Processor::create_fragment( string $html, string $context = '<body>' )` → `WP_HTML_Processor|null`. **Returns `null` on failure — guard it.**
- `next_token(): bool` — iterate every token (tags, text, comments, …).
- `get_token_type(): ?string` — `'#tag'`, `'#text'`, `'#comment'`, etc.
- `get_token_name()` / `get_tag(): ?string` — tag name, **uppercase** (e.g. `'SCRIPT'`).
- `is_tag_closer(): bool`.
- `get_modifiable_text(): string` — text of the current token.
- `get_breadcrumbs(): ?array` — ancestor tag names from root to current node, e.g.
  `['HTML','BODY','ARTICLE','H1']`, **uppercase**.

**Critical correctness note on `get_modifiable_text()`:** it returns the contents of
`<script>`, `<style>`, and `<textarea>` *as the modifiable text of those tag tokens*,
not as `#text` children. Therefore the safe rule is: **only read text from tokens where
`get_token_type() === '#text'`.** That alone excludes script/style bodies. Keep an
explicit skip-set on breadcrumbs as belt-and-suspenders.

#### Extraction algorithm
```
SKIP_ANCESTORS = { SCRIPT, STYLE, NOSCRIPT, TEMPLATE, NAV, ASIDE, FOOTER, FORM }
BOOST_BY_TAG   = { TITLE:5, H1:4, H2:3, H3:2, STRONG:2, EM:1.5, B:2 }  // configurable
DEFAULT_BOOST  = 1.0

processor = WP_HTML_Processor::create_fragment( $html )
if processor is null: treat as plain text, fall back to wp_strip_all_tags()

doc_lang = resolve_document_language(...)   // §3.3
segments = []
while processor.next_token():
    // Track language context: when on an opening tag, read get_attribute('lang')
    // and maintain a lang stack keyed by breadcrumb depth, so nested lang="…" wins.
    if processor.get_token_type() == '#tag' and not processor.is_tag_closer():
        push_lang_if_present( processor.get_attribute('lang') )   // BCP-47, e.g. "en", "pt-BR"
    if processor.get_token_type() != '#text': continue
    crumbs = processor.get_breadcrumbs() ?? []
    if any(c in SKIP_ANCESTORS for c in crumbs): continue
    text = processor.get_modifiable_text()
    if text trimmed is empty: continue
    weight = max( BOOST_BY_TAG.get(c, DEFAULT_BOOST) for c in crumbs )  // or product; pick one, document it
    lang   = current_lang_on_stack() ?? doc_lang
    segments.append( (text, weight, lang) )
return segments
```

`weight` is carried into the index as a multiplier on term frequency (see §4.3). `lang`
selects the per-language pipeline (§3.4–3.6) and namespaces the resulting terms (§4.2).
Decide **max-over-ancestors vs product-over-ancestors** for weight and freeze it; max is
the saner default (a `<strong>` inside an `<h1>` shouldn't compound to 8×).

A document with no per-element `lang` attributes yields a single language for all
segments (`doc_lang`). The lang-attribute tracking matters for genuinely mixed documents
— e.g. a Polish tutorial quoting English error messages, where `<code lang="en">` should
be analyzed with the English pipeline.

### 3.3 Language resolution
Determine the language/dialect of each document, segment, and query, in this priority
order:

1. **Explicit per-element `lang`** (BCP-47) from the HTML, for mixed-language documents
   (§3.2).
2. **Document-level language** from WordPress: a multilingual plugin if present
   (Polylang / WPML attach a language to each post), else the post's locale, else
   `get_locale()` / `get_bloginfo('language')` for the site default.
3. **Automatic detection** as a fallback when none of the above is available — a
   lightweight n-gram language detector over the segment text. Treat as best-effort; it
   is unreliable on short strings, so prefer explicit metadata.
4. **Configured default** language as the last resort.

For **queries**, resolve in this order: an explicit language passed by the caller (e.g. a
language switcher), the current request locale, the site default. On multilingual sites
the query language decides which language partition is searched (§6).

Normalize every resolved language to a canonical key `(language, dialect)` — e.g.
`pt-BR`, `en-GB`, `zh-Hant` — and reduce to the base language for stemmer selection while
keeping the dialect for normalization (§3.5).

### 3.4 Tokenization (script- and language-aware)
There is no single tokenizer. Select by the dominant Unicode script of the segment:

- **Space-delimited scripts** (Latin, Cyrillic, Greek, Arabic, Hebrew, …): the C-backed
  regex `preg_match_all('/[\p{L}\p{N}_]+/u', $text, $m)`.
- **Scripts without word spacing** (Chinese, Japanese, Korean Han; Thai, Lao, Khmer):
  the regex above would swallow a whole run as one token. Use **character n-grams**
  (CJK bigrams are the standard pragmatic choice) for these runs. A full dictionary
  segmenter (MeCab/Jieba-class) is out of scope for pure PHP v1; n-grams give acceptable
  recall without a dictionary.
- **Mixed scripts in one segment**: detect script per run (by Unicode block) and apply
  the matching tokenizer to each run.

Tokenizer selection is part of the per-language pipeline, so it is configured alongside
the stemmer and stopwords.

### 3.5 Normalization, folding, and dialects
- **Case:** `mb_strtolower($t, 'UTF-8')`.
- **Diacritic folding:** per-language, not global. Folding is right for some languages
  and wrong for others — e.g. German ß/ä/ö/ü have conventional expansions (`ß→ss`,
  `ä→ae`) rather than naive stripping, and Turkish dotted/dotless i must not be folded
  the Latin way. For Polish, fold so "Wrocław" matches "wroclaw" (`ł→l`, `ą→a`, …).
  Define the fold map per language; do not apply one global table.
- **Dialects** are handled as a normalization variant *within* a base language, sharing
  the language's stemmer:
  - Spelling normalization, e.g. en-GB ↔ en-US (`-ise/-ize`, `colour/color`), via a small
    variant map applied before stemming.
  - Chinese Traditional ↔ Simplified conversion (`zh-Hant` ↔ `zh-Hans`) so the two scripts
    are searchable interchangeably.
  - Indexing and query must apply the **same** dialect normalization, or recall breaks.
- Drop terms shorter than `MIN_TERM_LEN` (default 2, but **not for CJK**, where 1–2 char
  tokens are meaningful) and longer than `MAX_TERM_BYTES` (default 255, see schema).

### 3.6 Stemming
Stemming is **per-language and pluggable**. The realistic landscape, given the pure-PHP /
Playground constraint:

- **Snowball languages (off-the-shelf, pure PHP):** use `wamania/php-stemmer` — a native
  PHP implementation of the Snowball stemmers, MIT-licensed, ~2.7M installs, sole runtime
  dependency `joomla/string` (pure PHP, so it runs in Playground). It covers Catalan,
  Danish, Dutch, English, Finnish, French, German, Italian, Norwegian, Portuguese,
  Romanian, Russian, Spanish, and Swedish, selected by ISO-639 code. This is the same
  stemmer Relevanssi uses for multilingual WordPress search, which is good validation of
  the approach (it even drives stemmer language per document). **For the languages above,
  use it directly — do not write a stemmer.**
- **Polish — the gap, and it is your own language.** Polish is **not** in Snowball and not
  in `wamania/php-stemmer`. Rule-based suffix stripping works poorly for Polish because of
  its morphological complexity, so the credible options are:
  1. **Stempel** — an algorithmic, table-driven stemmer (Egothor-based, Apache-licensed),
     with stemming tables trained on a Polish corpus. The algorithm is compact and
     table-driven, so **porting it to PHP is feasible**: port the stemmer loop and ship
     the tables as a data file. Best balance of accuracy and OOV behavior for v1.
  2. **Morfologik** — a dictionary/FSA stemmer (lemmatizer). Very accurate for known
     forms, but fails on out-of-vocabulary words, and requires a finite-state-automaton
     reader plus the (sizeable) dictionary loaded in PHP.
  3. **Fold-only fallback** — aggressive folding plus light suffix stripping. Lossy;
     acceptable only as a stopgap until (1) or (2) lands.
  This is the single largest piece of net-new work the multilingual requirement adds, and
  it should be called out in planning. The same gap applies to other non-Snowball
  languages the corpus needs.
- **CJK:** stemming is not applicable; n-gram tokenization (§3.4) is the substitute.
- **Lemmatization vs stemming:** stemming (truncation to a stem) is what's specified.
  Dictionary lemmatization (to a base form) is more accurate and is what Morfologik does
  for Polish; treat it as an alternative implementation of this stage, not a separate
  pipeline.

The stemmer stage exposes a per-language interface so a language can be served by
`wamania`, a ported Stempel, a Morfologik-backed lemmatizer, or a no-op, interchangeably.

### 3.7 Query analysis
Resolve the query language (§3.3), then run stages 2–6 for **that language** (no HTML
stage). The query must pass through the **same tokenizer, normalization, dialect map, and
stemmer** as the documents it should match, or recall breaks. Because terms are namespaced
by language in the index (§4.2), a query analyzed as `pl` only matches Polish-indexed
terms — which is the desired behavior on a multilingual site.

---

## 4. Index data model

Two viable layouts. **Recommended: blob-per-term (4.2).** It is the layout that best
matches "I own the index," and it makes each query term a single indexed point lookup
returning a complete posting list, which is the fast path for search (§6, §8).

### 4.1 Layout A — row-per-posting (simpler, not recommended for read-heavy)
One row per `(term, doc)`. Easy to update; search is `WHERE term_id IN (...) GROUP BY
doc_id`. Downsides: huge row counts, scoring needs a scan/aggregate of many rows per
query. Use only if you want the simplest possible writer and corpora are small.

### 4.2 Layout B — blob-per-term (recommended)
One row per term holding the entire posting list as a packed binary blob. One indexed
read per query term returns everything needed for that term; PHP decodes and scores.

```sql
-- {prefix} = $wpdb->prefix

CREATE TABLE {prefix}fts_terms (
    term        VARBINARY(255) NOT NULL,     -- lang-namespaced normalized term (see notes)
    doc_freq    INT UNSIGNED   NOT NULL,     -- docs containing the term (within its language)
    postings    LONGBLOB       NOT NULL,     -- packed (see 4.3)
    PRIMARY KEY (term)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=binary;

CREATE TABLE {prefix}fts_docs (
    doc_id      BIGINT UNSIGNED NOT NULL,    -- post ID or app-defined ID
    lang        VARCHAR(16)     NOT NULL,    -- canonical (language[-dialect]) of the doc
    doc_len     INT UNSIGNED    NOT NULL,    -- total weighted token count (for BM25)
    content_hash CHAR(40)       NULL,        -- sha1 of analyzed input, for skip-if-unchanged
    PRIMARY KEY (doc_id),
    KEY lang (lang)
) ENGINE=InnoDB DEFAULT CHARSET=binary;

CREATE TABLE {prefix}fts_meta (
    lang VARCHAR(16) NOT NULL,               -- stats are per-language; '' = global if ever needed
    k    VARCHAR(64) NOT NULL,               -- 'doc_count' | 'len_sum'
    v    BIGINT      NOT NULL,
    PRIMARY KEY (lang, k)
) ENGINE=InnoDB DEFAULT CHARSET=binary;
```

Notes:
- Use `CHARSET=binary` / `VARBINARY` for `term`: terms are already normalized by the
  analyzer, so the storage layer must do **exact byte** matching, not collation-aware
  matching. This also dodges the utf8mb4 unique-key prefix-length problem.
- **Terms are namespaced by language.** Store each term as `lang . "\x1e" . term` (or a
  separate `lang` column on the terms table with a composite primary key). This makes
  `doc_freq` and the posting list **per-language**, so a query analyzed as `pl` cannot
  match `en` postings, and IDF is computed within the language. A document where a stemmer
  collides across languages (e.g. an identical token in two languages) correctly keeps two
  independent term rows.
- **Relevance statistics are per-language.** `N` and `avgdl` in BM25 (§6.2) must be the
  document count and average length **for the query's language**, kept in `fts_meta` keyed
  by `lang`. Computing them globally pollutes IDF: a term common in English would look
  rare if the collection is mostly Polish. `avgdl = len_sum(lang) / doc_count(lang)`.
- `mixed-language documents` (§3.2) contribute their tokens to multiple language
  partitions; `fts_docs.lang` records the document's *primary* language for reporting and
  for the default query partition, while the per-segment language governs which term
  namespace each token lands in.
- Create tables with `dbDelta()` (mind its formatting rules: two spaces after `PRIMARY
  KEY`, lowercase types, one definition per line).

**Partitioning decision.** Lang-namespaced terms in one set of tables (above) is the
recommended default — simplest, and keeps a single index. A fully **separate index per
language** (separate term/doc/meta tables per language) is the alternative: cleaner
isolation and trivially correct per-language stats, at the cost of more tables/files and a
router. Choose separate indexes only if a single language's corpus is large enough to want
its own storage lifecycle; otherwise namespace within one index.

### 4.3 Postings encoding (LEB128 varint)
Per term, postings is a sequence sorted ascending by `doc_id`:

```
postings := count, then `count` records of:
    delta_doc_id  (varint, gap from previous doc_id, first gap from 0)
    weighted_tf   (varint, rounded weighted term-frequency in that doc; see below)
```

- **Varint:** unsigned LEB128. Encode with `chr()`/`ord()` loops, or batch with
  `pack()`. PHP ints are 64-bit on 64-bit builds; doc_ids fit.
- **Weighted tf:** `Σ (occurrences_in_segment × segment_weight)`, then `round()` to an
  int ≥ 1. This is the cheap "BM25F-lite" (see §6.3). Store the weighted value, not the
  raw count.
- Delta-encoding the sorted doc_ids keeps the blob small and decode is a single linear
  pass.

### 4.4 Positions (optional, default OFF)
If phrase search is needed later, extend each record with a varint position count plus
delta-encoded positions. Roughly doubles index size. Do not build it in v1 unless
phrase queries are a confirmed requirement.

---

## 5. Indexing process

No hooks. Expose explicit methods. Suggested class `WP_FTS_Indexer`:

```php
index_document( int $doc_id, string $html, array $opts = [] ): void
delete_document( int $doc_id ): void
reindex_all( array $opts = [] ): int      // returns count; pulls source via $wpdb
flush(): void                             // for batched writers
```

### 5.1 Indexing one document
1. Compute `content_hash = sha1($html)` (or of the analyzed token stream). If
   `fts_docs.content_hash` matches, skip — nothing changed.
2. If the doc was previously indexed, run delete (§5.3) first so postings don't
   accumulate stale entries (and so stats are decremented from the doc's old language).
3. Resolve the document language (§3.3) and analyze → list of `(term, weighted_tf, lang)`
   and `doc_len = Σ weighted_tf`. For mixed-language docs, terms carry their segment's
   language; the document's primary language is recorded on `fts_docs`.
4. For each term, namespace it by its language (§4.2), then **read-modify-write** its
   `fts_terms` row — load `postings`, decode, insert/replace this `doc_id`'s record keeping
   the list sorted, re-encode, write back, bump `doc_freq` if the doc is newly present. Use
   `INSERT … ON DUPLICATE KEY UPDATE`.
5. Upsert `fts_docs (doc_id, lang, doc_len, content_hash)`.
6. Update `fts_meta` **for each language the document contributed tokens to**: increment
   `doc_count[lang]` and add to `len_sum[lang]`.

Wrap a document's writes in a transaction so a partial failure doesn't corrupt the
index. The blob read-modify-write is the expensive part of writing; batch many
documents per term where possible if you build a bulk reindexer (accumulate per-term
postings in PHP memory, then write each term once).

### 5.2 Batch reindex (the primary entry point, since there are no hooks)
Pull source rows directly via `$wpdb` in ID-ordered batches; do not load all posts at
once.

```php
$batch = 500;
$last  = 0;
do {
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT ID, post_content, post_title
         FROM {$wpdb->posts}
         WHERE post_status = 'publish' AND post_type = 'post'
               AND ID > %d
         ORDER BY ID ASC
         LIMIT %d", $last, $batch
    ) );
    foreach ( $rows as $r ) {
        $html = $r->post_title . "\n" . $r->post_content; // title boosted via analyzer
        $indexer->index_document( (int) $r->ID, $html );
        $last = (int) $r->ID;
    }
} while ( $rows );
$indexer->flush();
```

Expose this as a WP-CLI command (`wp fts reindex`) and/or a plain callable. The bulk
path should prefer the accumulate-then-write-once-per-term strategy over per-document
read-modify-write.

### 5.3 Deletion
Without a full term list for the doc, you must either (a) walk every term row (slow), or
(b) **tombstone**: mark `doc_id` removed in `fts_docs`, exclude tombstoned docs at query
time, and physically purge them from posting blobs during a periodic `optimize()`
compaction. Recommendation: tombstone + compaction (this is what php-fts does). For
single-doc reindex, since you re-analyze the doc you *do* know its terms, so you can
remove precisely — keep both paths.

---

## 6. Search process

### 6.1 Flow
1. Resolve the query language (§3.3) and analyze the query string with that language's
   pipeline (§3.7) → query terms `Q`, each namespaced to the query language.
2. Fetch postings for all terms in one round trip:
   `SELECT term, doc_freq, postings FROM {prefix}fts_terms WHERE term IN (…)`
   (prepared, with a placeholder per term; terms are already lang-namespaced).
3. Decode each blob → `[(doc_id, weighted_tf), …]`.
4. Load **per-language** stats from `fts_meta` for the query language
   (`N = doc_count[lang]`, `avgdl = len_sum[lang] / N`) and the `doc_len` for candidate
   docs (`SELECT doc_id, doc_len FROM {prefix}fts_docs WHERE doc_id IN (…)`), excluding
   tombstones.
5. Score each candidate doc with BM25 (§6.2), summing over query terms.
6. Apply boolean mode: **OR** = union of candidates (default), **AND** = keep only docs
   present in every term's postings.
7. Sort by score desc, take top `K`.
8. Hydrate: return `doc_id`s and let the caller fetch posts (`WP_Query` with
   `post__in` + `orderby => post__in`, or direct `$wpdb`).

Because terms and stats are language-partitioned, a single query searches one language by
default. If a deployment needs to search all of a site's languages at once, run step 2–6
per language and merge the ranked lists — but note BM25 scores are **not comparable across
languages** (different `N`/`avgdl`), so merge by interleaving or per-language top-K, not by
raw score. True cross-language retrieval (a Polish query matching English docs) needs
translation or multilingual embeddings and is out of scope.

### 6.2 BM25
For document `D`, query `Q`:

```
score(D,Q) = Σ_{t∈Q} IDF(t) · ( f · (k1 + 1) ) / ( f + k1 · (1 − b + b · |D| / avgdl) )

  f      = weighted_tf of term t in D
  |D|    = doc_len of D
  IDF(t) = ln( 1 + (N − df(t) + 0.5) / (df(t) + 0.5) )
  k1     = 1.2   (tunable 1.2–2.0)
  b      = 0.75
```

### 6.3 Field weighting — two options
- **v1 (recommended): weighted-tf.** Field boosts are already baked into `f` at index
  time (§4.3). Zero query-time cost. Slightly distorts BM25's term-frequency saturation,
  which is acceptable in practice.
- **Proper BM25F (optional).** Keep per-field lengths and per-field tf, normalize each
  field separately, then combine. More correct for documents with very uneven field
  lengths, materially more complex to store and compute. Defer unless v1 ranking proves
  inadequate.

---

## 7. Storage backend interface

This is the seam that lets the same engine run on MySQL and on a flat file (Playground).
Keep the engine ignorant of where postings live.

```php
interface WP_FTS_Storage {
    /** @param string[] $terms  @return array<string,array{df:int,postings:string}> */
    public function get_terms( array $terms ): array;
    public function put_term( string $term, int $df, string $postings ): void;
    /** @param int[] $doc_ids  @return array<int,int> doc_id => doc_len */
    public function get_doc_lengths( array $doc_ids ): array;
    public function put_doc( int $doc_id, int $doc_len, string $hash ): void;
    public function delete_doc( int $doc_id ): void;       // tombstone
    public function get_meta(): array;                     // ['doc_count'=>N,'len_sum'=>S]
    public function add_meta( int $d_docs, int $d_len ): void;
    public function optimize(): void;                      // compaction / purge tombstones
}
```

Implementations: `WP_FTS_Storage_Mysql` (via `$wpdb`, the tables in §4.2) and
`WP_FTS_Storage_File` (§8). The searcher and indexer depend only on this interface.

---

## 8. MySQL table vs. flat file — which is faster?

**Direct answer: for a normally hosted WordPress site, the MySQL blob-per-term layout is
the faster and simpler choice. A flat file only wins in specific conditions, the main
one being the Playground / no-MySQL target.**

Reasoning:

- **The search workload is a handful of point lookups.** With blob-per-term, each query
  term is one primary-key lookup on `fts_terms` returning a compact blob. MySQL/InnoDB is
  excellent at exactly this, and frequently-queried term rows live in the **InnoDB buffer
  pool**, i.e. served from RAM. The per-query DB cost is microseconds-to-low-ms of
  indexed reads plus the PHP decode/score.

- **PHP is shared-nothing, and that is what hurts a naive file index.** Nothing persists
  in PHP between requests. A flat-file index that you `unserialize`/load **in full on
  every request** pays the entire read+parse cost per query — for a multi-MB index this is
  usually *slower* than MySQL's cached point lookups. The buffer pool gives MySQL
  cross-request caching for free; a file approach does not get that unless you add it.

- **A file index can match or beat MySQL only if you do one of these:**
  1. Keep it **memory-resident across requests** — APCu, an OPcache-preloaded structure,
     or `shmop`/mmap — so you're not reloading per request.
  2. Build a **term → byte-offset dictionary** and `fseek()`/mmap to read only the needed
     posting lists, instead of loading the whole file. This is fast and DB-round-trip-free
     — but it is literally reimplementing the indexed point lookup that MySQL already
     gives you, plus your own locking for concurrent writes.
  3. You operate in an environment with **no MySQL** (Playground, where WP runs on SQLite)
     or where you have measured the DB round trip as the dominant cost.

- **Playground specifically:** there is no MySQL there, and a flat-file index that you
  mmap/seek (or load once into memory) is the natural fit. This is the entire reason §7
  exists — `WP_FTS_Storage_Mysql` for hosted sites, `WP_FTS_Storage_File` for Playground —
  same engine, swapped backend.

**Decision rule:**
- Hosted WP with MySQL, corpora from thousands to low millions of docs → **MySQL
  blob-per-term.** Don't hand-roll a file index; you'll re-implement the buffer pool and
  lose.
- Playground / no MySQL, or you've profiled the DB round trip as the bottleneck and can
  keep the index memory-resident → **flat file** with an offset dictionary (option 2) or
  APCu-resident (option 1).

If you want a single design that is robust across both: blob-per-term in MySQL on the
server, and a file backend whose on-disk format is *the same per-term blob* (§4.3)
indexed by a small `term → offset` table that you load once and cache. The posting
encoding is identical; only the addressing differs.

---

## 9. Correctness and edge cases

- **Script/style:** never indexed, because only `#text` tokens are read (§3.2). Verify
  with a fixture containing inline JS/CSS.
- **`create_fragment` returns null** on malformed input — fall back to
  `wp_strip_all_tags()` so a bad document still gets indexed as plain text.
- **Analyzer parity** between index and query paths is an invariant; add a test that
  asserts identical token output for the same input string through both paths.
- **Binary collation** on `term` is required for exact term matching; without it,
  collation folding will merge distinct normalized terms.
- **Empty index / unknown term:** `get_terms` returns nothing for that term; it
  contributes zero to the score, no error.
- **Concurrency:** blob read-modify-write races if two writers touch the same term. Wrap
  per-doc writes in a transaction; for the bulk reindexer, prefer single-writer
  (CLI) and the accumulate-then-write-once strategy.
- **Performance of `WP_HTML_Processor`:** core documents it as slower than the Tag
  Processor because it tracks full tree structure. That's the price of `get_breadcrumbs()`
  and it's the right trade for index quality. If index-build throughput becomes the
  bottleneck at very large scale, a Tag-Processor-plus-manual-tag-stack analyzer is the
  escape hatch — but keep the Processor for v1.
- **Tombstones** must be filtered at query time until `optimize()` purges them.
- **Language mismatch:** a query stemmed in the wrong language silently returns nothing
  useful (its namespaced terms won't exist). Resolve and log the query language; consider
  falling back to the site default language when the query language has an empty partition.
- **Per-language stats:** BM25 `N`/`avgdl` must come from the query language's partition,
  never the whole collection — verify with a mixed-language corpus where global vs
  per-language IDF diverge.
- **CJK term length:** the `MIN_TERM_LEN` floor must not discard 1–2 character CJK tokens;
  apply the floor only to space-delimited scripts.
- **Mixed-language document:** a doc with `lang` attributes must route each segment's
  tokens into the correct language namespace; assert a `<code lang="en">` block inside a
  Polish post is searchable from an English query and not from a Polish one.

---

## 10. Decisions to confirm before implementing

1. Boost combination: **max** vs product over ancestors. (Default: max.)
2. `BOOST_BY_TAG` map and `SKIP_ANCESTORS` set — confirm the element lists.
3. **Set of languages to support at launch**, and the source of truth for a document's
   language (Polylang / WPML / locale / detection). (Default: site languages from the
   multilingual plugin or locale; n-gram detection only as fallback.)
4. **Stemmer per language.** (Default: `wamania/php-stemmer` for the Snowball languages.)
5. **Polish (and other non-Snowball languages) strategy — the key open question:** port
   Stempel, ship Morfologik FSA + reader, or fold-only stopgap. This is the largest
   net-new work item; decide before committing a timeline.
6. **CJK / non-spacing scripts:** n-gram tokenization in or out for v1, and bigram vs
   trigram. (Default: CJK bigrams in.)
7. **Dialect handling:** which dialect normalizations to ship (en-GB↔en-US spelling,
   zh-Hant↔zh-Hans), and whether dialects collapse to one base language.
8. **Cross-language search:** single-language-per-query (default) vs merge-across-languages
   (and if merging, the merge strategy, since BM25 scores aren't cross-language comparable).
9. Per-language folding maps and stopword lists.
10. Default boolean mode: **OR** vs AND. (Default: OR.)
11. BM25 `k1`, `b`. (Defaults: 1.2, 0.75.)
12. Phrase support / positions: in or out for v1. (Default: out.)
13. Field weighting: weighted-tf vs full BM25F. (Default: weighted-tf.)
14. Source scope for `reindex_all`: which `post_type`s / `post_status`es, and whether to
    index custom fields.
---

## 11. Testing and acceptance

### 11.1 Strategy: oracle and differential testing

You cannot hand-author expected outputs for a search engine at any real scale, so do
not try. Almost every layer here has a **simpler oracle** it can be checked against, and
the test suite is built around those rather than around example assertions:

- The indexed engine is checked against a **brute-force linear-scan searcher** (no index
  at all) — its own correctness oracle.
- Your scoring is checked against an **external BM25 reference** (`rank-bm25` / `bm25s`)
  so a shared wrong assumption between engine and oracle still gets caught.
- The **MySQL and file backends are oracles for each other** (must return identical
  results).
- **Incremental indexing is checked against full reindex** (must converge to the same
  state).

Prefer property/randomized inputs over fixed cases wherever an oracle exists; that turns
a handful of harnesses into effectively unlimited coverage.

### 11.2 Test layers

Ordered by how many classes of bug each catches.

#### T1 — Brute-force oracle (correctness backbone, build this first)
A deliberately naive searcher: for a query, linearly scan every document, tokenize with
the **same analyzer**, count term frequencies, compute BM25 directly. No index, no blobs.

```
test_oracle_parity:
    corpus = N random/fixture docs
    index corpus through the real engine
    for each of M random queries:
        expected = brute_force.search(query)      # [(doc_id, score), …]
        actual   = engine.search(query)
        assert same doc_id set
        assert scores equal within |Δ| ≤ 1e-6 (relative)
```

Validates tokenization, posting encode/decode, df and doc-length bookkeeping, and the
BM25 computation simultaneously. Run with hundreds of generated corpora/queries.

#### T2 — Analyzer correctness
HTML fixture → expected token stream. Required assertions:
- Inline `<script>` and `<style>` bodies produce **zero** tokens.
- A term inside `<h1>` carries the configured heading weight; same term in a `<p>`
  carries `DEFAULT_BOOST`.
- `SKIP_ANCESTORS` elements (NAV, ASIDE, …) contribute nothing.
- `create_fragment()` returning `null` falls back to `wp_strip_all_tags()` and still
  indexes plain text.

Do **not** re-test the HTML parser itself — WP core already runs the HTML API against the
html5lib-tests conformance suite upstream. Borrow the gnarliest fixtures from
html5lib-tests (malformed nesting, implied/auto-closed tags, CDATA, raw-text elements)
purely to confirm the breadcrumb-based skip/boost logic survives real-world HTML.

#### T3 — Index/query parity invariant (property test)
```
for each of K random strings s:
    assert analyzer.tokenize_query(s) == analyzer.tokenize_content(strip_html(s))
```
Guards the recall-killing bug where the two paths normalize differently. Must hold 100%.

#### T4 — External scoring reference
Compute reference BM25 scores for a small fixed corpus with `rank-bm25` or `bm25s`
(Python), assert the engine matches within epsilon. **Pin the variant.** The spec's IDF
is Lucene-style (`ln(1 + …)`), so the reference must use the Lucene IDF — in `bm25s`,
`BM25(method="atire", idf_method="lucene")`. BM25 variants differ chiefly in the IDF
term; mismatching them produces phantom failures that are not bugs in your engine.

#### T5 — Backend differential
```
build identical corpus into WP_FTS_Storage_Mysql and WP_FTS_Storage_File
for each query: assert mysql_engine.search(q) == file_engine.search(q)  # ranking identical
```
The two storage implementations validate each other and exercise the §7 interface seam.

#### T6 — Incremental vs batch convergence (metamorphic)
```
A = engine after a scripted sequence of index/update/delete operations
B = engine after delete-all + full reindex_all() of the final document set
assert A.index_state == B.index_state         # term rows, postings, doc_lens, meta
assert A.search(q) == B.search(q) for all q in suite
```
Catches the read-modify-write and tombstone bugs from §5.3 — the ones that corrupt
silently. Include at least: re-index of a changed doc, delete then re-add, and a delete
that leaves a term with zero docs.

#### T7 — Boolean semantics and edge cases
- AND keeps only docs present in every query term; OR unions.
- Tombstoned docs never appear in results until `optimize()`, and never after.
- Empty index returns empty, no error.
- Unknown term contributes zero, no error.
- Terms at `MIN_TERM_LEN` / `MAX_TERM_BYTES` boundaries handled correctly.
- Folding round-trip: query "Wrocław" matches content "wroclaw" and vice versa.
- Binary collation: two terms differing only by case/diacritics that normalize to the
  same term collapse to one term row; ones that don't stay distinct.

#### T8 — Multilingual, stemming, and dialects
- **Per-language analyzer fixtures:** for each supported language, input → expected token
  stream after tokenize + normalize + stem. Include a CJK fixture asserting n-gram tokens
  (not one giant token), and a Polish fixture asserting the chosen Polish stemmer's output.
- **Stemmer differential:** for the Snowball languages, assert the engine's stems match
  `wamania/php-stemmer` (or the official Snowball reference vocabulary outputs) for a word
  list per language. For Polish, assert against the reference implementation you ported
  (Stempel/Morfologik test vectors).
- **Language resolution:** a doc tagged `pl` indexes Polish terms; a query in `pl` hits
  them; the same query analyzed as `en` does not. Mixed-language doc (`<code lang="en">`
  inside Polish content) routes the English block to the `en` namespace only.
- **Per-language statistics:** build a corpus that is 90% Polish, 10% English; assert the
  IDF/score for an English term uses `N`/`avgdl` from the English partition, and that a
  naive global-stats computation would differ — i.e. the test fails if stats are global.
- **Dialect normalization:** "colour" (en-GB) and "color" (en-US) resolve to the same
  term; zh-Hant and zh-Hans forms of the same word are mutually findable.
- **Cross-language merge (if enabled):** results from per-language searches are merged by
  the chosen strategy, and the test asserts raw BM25 scores are *not* compared across
  languages.

### 11.3 Ranking-quality evaluation (separate from correctness)

Once correct, measure whether results are *good* using a TREC-style collection —
documents, queries, and relevance judgments (qrels) — scored with `trec_eval` (or
`pytrec_eval`, pip-installable) on **nDCG@10, MAP, and P@5**.

- **Collection:** adapt **Cranfield** (≈1,400 docs, megabyte-scale, with an exhaustive
  set of relevance judgments) — small enough to run in CI as a regression gate. BEIR is
  the heavier, web-like option for later.
- **Differential quality gate:** run a reference BM25 (`rank-bm25`/`bm25s`, same variant)
  over the same Cranfield collection and require the engine's nDCG@10 to land within a
  small delta of it. Since both are BM25, a gap means your end-to-end pipeline (analysis,
  encoding, scoring) is silently degrading — a more sensitive signal than an absolute
  threshold.
- **Where quality actually comes from:** the IR literature finds BM25-variant choice has
  no significant effect on effectiveness, while mundane settings like stopwords often
  matter more. So treat the variant as a correctness/parity concern (T4), and spend
  quality-tuning effort on the analyzer instead — stopwords, folding, stemming, and the
  `BOOST_BY_TAG` map. Tune those against the Cranfield metrics; leave k1/b near defaults.

### 11.4 Adapt vs. build

**Adapt (don't reinvent):**
- html5lib-tests fixtures — weird-HTML edge cases for T2.
- `rank-bm25` / `bm25s` — scoring oracle for T4 and the quality gate.
- `wamania/php-stemmer` — the actual stemmer for Snowball languages, and the differential
  reference for stemmer tests (T8). Snowball's published reference vocabularies are the
  ground truth behind it.
- Stempel / Morfologik test vectors — Polish stemmer ground truth (T8), once a Polish
  implementation is chosen.
- Cranfield + `trec_eval`/`pytrec_eval` — quality evaluation (11.3).
- php-fts and TNTSearch PHPUnit layouts — structural template for organizing PHP FTS
  tests in the Composer idiom.

**Build (small, high-leverage, a few dozen lines each):**
- The brute-force oracle searcher (T1).
- The parity property test (T3).
- The backend differential harness (T5).
- The incremental-vs-batch convergence test (T6).

### 11.5 Definition of done (acceptance gates)

The implementation is accepted when **all** of the following pass in CI:

1. **T1** holds over ≥200 generated corpus/query combinations, scores within 1e-6.
2. **T2** analyzer fixtures pass, including script/style exclusion and heading boost.
3. **T3** parity invariant holds for ≥1,000 random strings (100%).
4. **T4** engine scores match the Lucene-IDF reference within 1e-6 on the fixed corpus.
5. **T5** MySQL and file backends return identical rankings for the full query suite.
6. **T6** incremental sequence converges exactly to full reindex (state and results).
7. **T7** every boolean/edge case asserts as specified.
8. **T8** per-language analyzer/stemmer fixtures pass; stemmers match their references;
   language resolution and per-language stats behave correctly on a mixed-language corpus;
   the chosen Polish stemmer passes its vectors.
9. **Quality:** nDCG@10 on Cranfield is within the agreed delta of the reference BM25.
10. All §10 decisions are resolved and reflected in config defaults and tests.
