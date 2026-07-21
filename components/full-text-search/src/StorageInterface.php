<?php
declare(strict_types=1);

/**
 * Posting-list storage contract used by the in-memory test oracle.
 *
 * Implementations store postings by namespaced term key, document metadata with
 * per-language lengths, and collection metadata used by BM25.
 */
interface WP_FTS_Storage
{
    /**
     * Fetch term rows for the requested stored keys.
     *
     * @param string[] $terms
     * @return array<string,array{df:int,postings:string}> Map of stored term key
     *         to document frequency and binary postings blob. Missing terms are
     *         omitted.
     */
    public function get_terms(array $terms): array;

    /**
     * Insert, replace, or remove one term row.
     *
     * `$term` is normally `lang . "\\x1e" . normalized_term`. Passing `$df <= 0`
     * should remove the row, matching the indexer's empty-postings behavior.
     *
     * @param string $term Stored term key.
     * @param int $df Document frequency for the decoded postings.
     * @param string $postings Binary postings blob from `WP_FTS_PostingsCodec`.
     */
    public function put_term(string $term, int $df, string $postings): void;

    /**
     * Delete one stored term row if present.
     *
     * @param string $term Stored term key.
     */
    public function delete_term(string $term): void;

    /**
     * Return active document lengths for scoring.
     *
     * When `$lang` is null, lengths are aggregate document lengths. When `$lang`
     * is supplied, only documents with a positive length in that language
     * partition should be returned.
     *
     * @param int[] $doc_ids
     * @return array<int,int> doc_id => doc length in $lang, or total length when $lang is null
     */
    public function get_doc_lengths(array $doc_ids, ?string $lang = null): array;

    /**
     * Fetch stored metadata for one document id.
     *
     * @return array{primary_lang:string,lang_lengths:array<string,int>,doc_len:int,content_hash:?string,deleted:bool}|null
     *         Null when the id has never been stored. Tombstones return
     *         `deleted => true`.
     */
    public function get_doc(int $doc_id): ?array;

    /**
     * Store or replace document metadata.
     *
     * @param string $primary_lang Canonical primary language.
     * @param array<string,int> $lang_lengths Per-language lengths.
     * @param string|null $hash Content hash.
     */
    public function put_doc(int $doc_id, string $primary_lang, array $lang_lengths, ?string $hash): void;

    /**
     * Mark a document as deleted.
     *
     * Implementations may keep a tombstone until `optimize()` compacts postings.
     * Search must treat deleted documents as inactive by excluding their lengths.
     */
    public function delete_doc(int $doc_id): void;

    /**
     * Return collection metadata for BM25.
     *
     * With `$lang`, values are for that language partition. Without `$lang`,
     * values are aggregate totals across active documents.
     *
     * @return array{doc_count:int,len_sum:int}
     */
    public function get_meta(?string $lang = null): array;

    /**
     * Add signed deltas to collection metadata.
     *
     * Implementations should clamp stored totals at zero.
     */
    public function add_meta(string $lang, int $d_docs, int $d_len): void;

    /**
     * List all term keys currently stored.
     *
     * @return string[]
     */
    public function all_terms(): array;

    /**
     * List known document ids.
     *
     * @param bool $include_deleted Include tombstoned ids when true.
     * @return int[]
     */
    public function all_doc_ids(bool $include_deleted = false): array;

    /**
     * Start a transaction or equivalent rollback scope.
     */
    public function begin_transaction(): void;

    /**
     * Commit the current transaction or rollback scope.
     */
    public function commit(): void;

    /**
     * Roll back the current transaction or rollback scope.
     */
    public function rollback(): void;

    /**
     * Flush buffered writes to durable storage when applicable.
     */
    public function flush(): void;

    /**
     * Compact tombstones, rebuild derived metadata, or perform backend tuning.
     */
    public function optimize(): void;
}

/**
 * Storage contract for set-oriented search and pagination.
 *
 * Backends implementing this contract own dictionary resolution, candidate
 * discovery, visibility filtering, ranking, and pagination in the database.
 * The searcher supplies one analyzed logical-group plan and never expands
 * prefixes or reads postings through the legacy storage primitives first.
 */
interface WP_FTS_Set_Oriented_Search_Storage
{
    public const MAX_QUERY_GROUPS = 12;
    public const MAX_ALTERNATIVES_PER_GROUP = 12;
    public const MAX_QUERY_ALTERNATIVES = 12;
    public const MAX_PAGE_SIZE = 50;

    /**
     * Return one bounded ranked page for pre-prefix logical query groups.
     *
     * `$options['limit']` includes the caller's one-row lookahead. Backends may
     * return that many result rows or return at most `page_size` rows together
     * with an explicit `has_more` value and opaque cursors. Interactive totals
     * are intentionally unknown; implementations must not perform an exhaustive
     * count merely to populate the response. The input is hard-bounded to 12
     * logical groups and 12 alternatives total. The aggregate cap preserves a
     * twelve-way morphology group or twelve ordinary words without permitting
     * a 48-posting-range OR plan.
     * A backend should emit only term ids/group ids/ranks into its SQL constant
     * relation; `source` and `surface` are presentation-side analyzer context.
     *
     * When `include_metadata` is true, each of the first `page_size` rows may
     * carry a `metadata` array or the canonical WordPress metadata columns.
     * When `include_snippets` is true, those rows carry bounded raw
     * `snippet_text`, not escaped or highlighted HTML. The searcher consumes the
     * sidecars after slicing off the lookahead row, generates the final safe
     * snippet, and removes `metadata`/`snippet_text` from the public result. This
     * contract keeps all hydration page-sized and forbids follow-up document or
     * metadata reads in the searcher.
     *
     * @param array<int,array<int,array{key:string,lang:string,term:string,rank:int,source?:string,surface?:string}>> $groups
     * @param array<string,mixed> $options Normalized, bounded search options.
     * @return array<string,mixed> Page payload containing at most `limit`
     *         `results` plus optional `has_more`, `next_cursor`,
     *         `previous_cursor`, `query_lang`, and bounded `explain` values.
     */
    public function search_page(array $groups, array $options): array;
}

/**
 * Optional writer extension for backends that replace one document's posting rows.
 *
 * The narrow contract lets a set-oriented production backend publish postings
 * without claiming that it can materialize posting lists for readers.
 */
interface WP_FTS_Row_Postings_Writer_Storage extends WP_FTS_Storage
{
    /**
     * Replace all postings for one document id.
     *
     * Implementations must remove existing rows for `$doc_id`, write the new
     * `(term, doc_id, tf)` rows, and update document frequencies consistently
     * inside the caller's transaction.
     *
     * @param array<string,int> $term_frequencies Stored term key => weighted tf.
     */
    public function replace_doc_postings(int $doc_id, array $term_frequencies): void;
}

/**
 * Optional read/write extension for backends that expose individual posting rows.
 *
 * The base storage interface keeps the blob-shaped `get_terms()`/`put_term()`
 * contract used by the test oracle. Implementations of this wider contract
 * support both native per-document replacement and bounded callers that
 * explicitly request decoded posting maps.
 */
interface WP_FTS_Row_Postings_Storage extends WP_FTS_Row_Postings_Writer_Storage
{

    /**
     * Fetch postings for requested stored term keys only.
     *
     * Missing terms are omitted. Returned posting maps must be sorted by
     * document id so scoring and tests remain deterministic.
     *
     * @param string[] $terms Stored term keys.
     * @return array<string,array<int,int>> term => doc_id => weighted tf
     */
    public function get_postings(array $terms): array;
}

/**
 * Optional approximate extension for document-id-capped retrieval.
 *
 * Implementations may return at most `$candidate_cap` postings per requested
 * term, in deterministic document-id order. This is only used after explicit
 * approximate opt-in; exact search continues to use the full row-postings
 * contract.
 */
interface WP_FTS_Capped_Postings_Storage extends WP_FTS_Storage
{
    /**
     * @param string[] $terms Stored term keys.
     * @return array<string,array<int,int>> term => doc_id => weighted tf
     */
    public function get_capped_postings(array $terms, int $candidate_cap): array;
}

/**
 * Optional storage extension for one globally bounded postings read.
 *
 * Search request budgets use this instead of materializing every requested
 * posting list and trimming it afterward. Implementations may return fewer
 * rows because budgeted search is explicitly approximate once a per-term cap
 * is supplied.
 */
interface WP_FTS_Budgeted_Postings_Storage extends WP_FTS_Storage
{
    /**
     * @param string[] $terms Stored term keys.
     * @param int|null $candidate_cap Optional maximum rows retained per term.
     * @param int $row_cap Maximum posting rows returned across all terms.
     * @return array<string,array<int,int>> term => doc_id => weighted tf
     */
    public function get_budgeted_postings(array $terms, ?int $candidate_cap, int $row_cap): array;
}

/**
 * Optional storage extension for backends that can read document terms directly.
 *
 * Blob-backed stores can derive this through `all_terms()` plus decoded
 * postings, but row-postings stores should expose a direct lookup so admin
 * diagnostics do not scan the full term table.
 */
interface WP_FTS_Document_Terms_Storage extends WP_FTS_Storage
{
    /**
     * Return stored term keys currently posted by one document id.
     *
     * @return string[] Sorted stored term keys.
     */
    public function terms_for_doc(int $doc_id): array;
}

/**
 * Optional storage extension for prefix expansion over stored term keys.
 *
 * Implementations must return stored term keys in deterministic ascending order
 * and cap the result count. Row-backed stores should answer this with an indexed
 * range lookup over the term key; blob-backed stores may derive it from their
 * in-memory key map.
 */
interface WP_FTS_Prefix_Term_Storage extends WP_FTS_Storage
{
    /**
     * Return stored term keys that start with `$prefix`.
     *
     * @return string[] Sorted stored term keys, capped to `$limit`.
     */
    public function terms_with_prefix(string $prefix, int $limit): array;
}

/**
 * Storage capability for table-preserving whole-index resets.
 *
 * Implementations must remove only derived FTS rows and leave schema/table
 * contracts intact so a later reindex can repopulate the same backend.
 */
interface WP_FTS_Resettable_Storage
{
    /**
     * Clear all indexed document, posting, term, and collection metadata rows.
     *
     * Production DDL resets may report null row counts rather than scan the
     * corpus solely to make destructive-operation counters exact.
     *
     * @return array<string,mixed> Reset strategy, count precision, and optional row counts.
     */
    public function reset_index(): array;
}
