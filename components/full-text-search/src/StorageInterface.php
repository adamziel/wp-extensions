<?php
declare(strict_types=1);

/**
 * Storage contract for relational search and cursor pagination.
 *
 * The backend owns dictionary resolution, candidate discovery, visibility,
 * ranking, hydration, and pagination. The searcher supplies one bounded set of
 * analyzed logical groups. It never reads posting lists or expands prefixes in
 * PHP.
 */
interface WP_FTS_Set_Oriented_Search_Storage
{
    public const MAX_QUERY_BYTES = 4096;
    public const MAX_MODE_BYTES = 8;
    public const MAX_LANGUAGE_BYTES = 64;
    public const MAX_CURSOR_BYTES = 2048;
    public const MAX_FILTER_VALUES = 32;
    public const MAX_FILTER_VALUE_BYTES = 64;
    public const MAX_FILTER_BYTES = 4096;
    public const MAX_OPTION_KEY_BYTES = 64;
    public const MIN_SNIPPET_LENGTH = 40;
    public const MAX_SNIPPET_LENGTH = 500;
    public const MAX_METADATA_NAME_BYTES = 64;
    public const MAX_CANONICAL_FIELDS = 64;
    public const MAX_CANONICAL_KEY_BYTES = 64;
    public const MIN_PREFIX_LENGTH = 2;
    public const MAX_PREFIX_LENGTH = 12;
    public const DEFAULT_PREFIX_LENGTH = 4;
    public const MIN_RECENCY_BOOST_STRENGTH = 0.0;
    public const MAX_RECENCY_BOOST_STRENGTH = 2.0;
    public const MIN_RECENCY_BOOST_HALF_LIFE_DAYS = 1.0;
    public const MAX_RECENCY_BOOST_HALF_LIFE_DAYS = 3650.0;
    public const DEFAULT_RECENCY_BOOST_HALF_LIFE_DAYS = 30.0;
    public const MAX_QUERY_GROUPS = 12;
    public const MAX_ALTERNATIVES_PER_GROUP = 12;
    public const MAX_QUERY_ALTERNATIVES = 12;
    public const MAX_PAGE_SIZE = 50;
    public const MAX_APPROXIMATE_CANDIDATE_BUDGET = 100000;
    public const MAX_METADATA_TITLE_BYTES = 20000;
    public const MAX_SNIPPET_SOURCE_BYTES = 20000;
    public const MAX_SIDECAR_PAGE_BYTES = 4194304;
    public const MAX_QUERY_STATEMENTS = 3;

    /**
     * Return one bounded ranked page for pre-prefix logical query groups.
     *
     * The backend may derive one lookahead row from `$options['page_size']`, but
     * it returns at most `page_size` result rows with a native `has_more` value
     * and exact opaque string-or-null cursors. Interactive totals are
     * deliberately unknown; implementations must not run an exhaustive count
     * merely to populate the response. Input is capped at 12 logical groups and 12
     * alternatives in total. The aggregate cap permits either one twelve-way
     * morphology group or twelve ordinary words without permitting a
     * 48-posting-range OR plan. Both nesting levels are lists, and every
     * alternative contains exactly one canonical `key` and native integer
     * `rank`. A backend should put only term ids, group ids, and ranks in its
     * SQL constant relation. Analyzer context used for presentation stays with
     * the searcher instead of crossing this boundary.
     *
     * Every row contains a native positive integer `doc_id` and finite float
     * `score`, in that order, and a page may not repeat an ID. When
     * `include_metadata` is true, every row carries the canonical WordPress
     * metadata columns and a canonical `primary_lang`: type and status are
     * nonempty unpadded strings of at most 64 bytes, the UTC date has the exact
     * WordPress datetime shape, and title is at most 20,000 bytes. Metadata is
     * valid UTF-8 and its aggregate page is at most 4 MiB. When
     * `include_snippets` is true, every row carries at most 20,000 bytes of raw
     * `snippet_text` and that same exact language field. The searcher turns the
     * snippet sidecar into safe HTML and removes the raw source from the public
     * result. Hydration must remain page-sized; the searcher performs no
     * follow-up document or metadata reads.
     *
     * The private canonical WordPress sidecar is a nonempty scalar map of at
     * most 64 fields and 4 MiB per page. `ID` comes first as a native integer
     * equal to `doc_id`; every other value is a native UTF-8 string.
     *
     * When `explain` is true, the page must include the fixed relational map:
     * storage identity, logical/resolved counts, optional anchor group, prefix
     * flag and strategy, statement count, the unknown-total marker, exact
     * recency settings, and canonical page bytes. It contains no other fields
     * or per-result diagnostics.
     *
     * When `approximate_candidate_budget` is present, the page marks the
     * retrieval as approximate, exposes the active budget, and reports the
     * dictionary-planned posting rows before the candidate set is capped.
     *
     * @param array<int,array<int,array{key:string,rank:int}>> $groups
     * @param array<string,mixed> $options Normalized, bounded search options.
     * @return array{results:array<int,array<string,mixed>>,has_more:bool,next_cursor:?string,previous_cursor:?string,retrieval_mode?:string,results_may_be_incomplete?:bool,planned_posting_rows?:int,candidate_budget?:int,explain?:array<string,mixed>}
     */
    public function search_page(array $groups, array $options): array;
}
