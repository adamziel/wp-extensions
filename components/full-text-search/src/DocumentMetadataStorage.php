<?php
declare(strict_types=1);

/**
 * Optional storage capability for product-facing document metadata.
 *
 * The postings contract stays focused on term statistics. Backends that support
 * search filters, snippets, and WordPress result fields expose this companion
 * interface so the searcher can enrich results without forcing legacy storage
 * implementations to grow new methods.
 */
interface WP_FTS_DocumentMetadataStorage
{
    /**
     * Store bounded product metadata for one active document.
     *
     * @param array<string,mixed> $metadata Normalized or raw metadata. Backends
     *        should normalize scalar fields and preserve structured extras such
     *        as terms/custom fields where feasible.
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
