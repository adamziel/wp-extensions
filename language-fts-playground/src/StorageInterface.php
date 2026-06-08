<?php
declare(strict_types=1);

interface Language_FTS_Playground_Storage_Interface
{
    public function install(): void;

    public function clear(): void;

    /**
     * @param array<string,int> $term_frequencies
     */
    public function replace_document(
        int $post_id,
        string $language,
        string $title,
        string $status,
        int $document_length,
        array $term_frequencies
    ): void;

    public function delete_document(int $post_id): void;

    /**
     * @param string[] $terms
     * @return array<string,array<int,int>>
     */
    public function fetch_postings(string $language, array $terms): array;

    /**
     * @param int[] $post_ids
     * @return array<int,int>
     */
    public function fetch_document_lengths(string $language, array $post_ids): array;

    public function document_count(string $language): int;

    /**
     * @return array<int,array{post_id:int,language:string,title:string,status:string,document_length:int,updated_at:string}>
     */
    public function all_documents(): array;
}
