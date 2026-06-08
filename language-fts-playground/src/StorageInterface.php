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

    public function delete_document(int $post_id): void;

    /**
     * @param string[] $terms
     * @return array<string,array<int,array<string,int>>>
     */
    public function fetch_postings(string $language, array $terms): array;

    /**
     * @param string[] $terms
     * @param int[] $post_ids
     * @return array<string,array<int,int[]>>
     */
    public function fetch_positions(string $language, array $terms, array $post_ids): array;

    /**
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

    public function document_count(string $language): int;

    /**
     * @return array<int,array{post_id:int,language:string,title:string,status:string,document_length:int,updated_at:string}>
     */
    public function all_documents(): array;
}
