<?php
declare(strict_types=1);

interface Language_FTS_Playground_Storage_Interface
{
    public function install(): void;

    public function clear(): void;

    /**
     * @param array<string,array<string,int>> $field_term_frequencies
     * @param array<string,string> $field_texts
     * @param array<string,int[]> $term_positions
     */
    public function replace_document(
        int $post_id,
        string $language,
        string $title,
        string $status,
        int $document_length,
        array $field_term_frequencies,
        array $field_texts,
        array $term_positions
    ): void;

    /**
     * Replaces the complete set of language partitions for a post.
     *
     * Each partition describes one language-specific document row for the same
     * post ID and contains the same field/position payloads as replace_document().
     *
     * @param array<int,array{language:string,title:string,status:string,document_length:int,field_term_frequencies:array<string,array<string,int>>,field_texts:array<string,string>,term_positions:array<string,int[]>,field_metadata?:array<string,array{language:string,language_provenance:string}>}> $partitions
     */
    public function replace_document_partitions(int $post_id, array $partitions): void;

    public function delete_document(int $post_id): void;

    /**
     * @param string[] $terms
     * @return array<string,array<int,array<string,int>>>
     */
    public function fetch_postings(string $language, array $terms): array;

    /**
     * @param array<string,string[]> $language_terms
     * @return array<string,array<string,bool>>
     */
    public function fetch_term_language_hits(array $language_terms): array;

    /**
     * @param string[] $terms
     * @param int[] $post_ids
     * @return array<string,array<int,int[]>>
     */
    public function fetch_positions(string $language, array $terms, array $post_ids): array;

    /**
     * Returns distinct fuzzy candidate terms for the requested language.
     *
     * Implementations should use length-band indexes to narrow the scan, then
     * apply edit-distance filtering before enforcing $limit. Searcher performs
     * the same filtering defensively, but storage must not truncate the raw
     * length band before edit-distance eligibility is known.
     *
     * @return string[]
     */
    public function fetch_candidate_terms(string $language, string $term, int $max_distance, int $limit): array;

    /**
     * @param int[] $post_ids
     * @return array<int,int>
     */
    public function fetch_document_lengths(string $language, array $post_ids): array;

    /**
     * @param int[] $post_ids
     * @return array<int,array<string,string>>
     */
    public function fetch_document_fields(string $language, array $post_ids): array;

    /**
     * @param int[] $post_ids
     * @return array<int,array<string,array{language:string,language_provenance:string}>>
     */
    public function fetch_document_field_metadata(string $language, array $post_ids): array;

    public function document_count(string $language): int;

    /**
     * @return array<int,array{post_id:int,language:string,title:string,status:string,document_length:int,updated_at:string}>
     */
    public function all_documents(): array;
}
