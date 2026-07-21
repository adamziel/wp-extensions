<?php
declare(strict_types=1);

/**
 * Optional storage capability for product-facing document metadata.
 *
 * The postings contract stays focused on term statistics. Backends that support
 * search filters, snippets, and WordPress result fields expose this companion
 * interface so the searcher can enrich results without forcing every storage
 * implementation to grow new methods.
 */
interface WP_FTS_DocumentMetadataStorage
{
    /**
     * Store bounded product metadata for one active document.
     *
     * @param array<string,mixed> $metadata Normalized or raw metadata. An empty
     *        array clears metadata for an existing document without creating a
     *        document. Backends should normalize scalar fields and preserve
     *        structured extras such as terms/custom fields where feasible.
     */
    public function put_doc_metadata(int $doc_id, array $metadata): void;

    /**
     * Fetch metadata for active documents.
     *
     * Deleted documents and unknown ids should be omitted from the result.
     *
     * @param int[] $doc_ids
     * @return array<int,array<string,mixed>> Metadata keyed by document id.
     */
    public function get_doc_metadata(array $doc_ids): array;
}

/**
 * Optional storage capability for filtering by indexed metadata fields.
 *
 * Search only needs a small scalar subset of metadata to apply post type,
 * status, and date filters. Backends that expose this capability can avoid
 * hydrating full snippet/result metadata for every candidate before pagination.
 */
interface WP_FTS_DocumentMetadataFilterStorage
{
    /**
     * Return active document ids whose stored metadata matches all filters.
     *
     * Empty filter arrays mean "any value". Date filters are inclusive and use
     * the same normalized GMT string boundaries as `WP_FTS_Searcher`.
     *
     * @param int[] $doc_ids Candidate document ids to filter.
     * @param string[] $post_types Allowed post types.
     * @param string[] $post_statuses Allowed post statuses.
     * @return int[] Sorted matching document ids.
     */
    public function filter_doc_ids_by_metadata(
        array $doc_ids,
        array $post_types = [],
        array $post_statuses = [],
        ?string $date_after = null,
        ?string $date_before = null
    ): array;
}
